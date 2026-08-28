<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pageview serveur automatique : imblocable par les adblockers, zéro JS.
 * L'envoi a lieu dans terminate(), après l'envoi de la réponse au visiteur :
 * aucun impact sur la latence perçue.
 *
 * Le contexte vient de l'objet Request (et non des superglobales) : correct
 * sous Octane/workers persistants, dans les tests, et aligné sur les
 * trusted proxies configurés dans l'application hôte. Le marqueur d'exclusion
 * suit la même règle : lu sur la Request, écrit sur la Response.
 */
final class TrackPageview
{
    public function __construct(private readonly Client $client) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        // Le marqueur d'exclusion se pose ici et pas dans terminate() :
        // terminate() s'exécute APRÈS l'envoi de la réponse au visiteur, il y
        // serait trop tard pour ajouter un en-tête.
        $signal = self::optOutSignal($request);
        if ($signal !== null && $response instanceof Response) {
            $this->writeOptOutMarker($request, $response, $signal);
        }

        // La fenêtre de visite se pose ici pour la même raison, et seulement
        // si ce hit est mesuré : la fenêtre suit le hit, pas la requête. La
        // décision est prise sur la réponse déjà construite, donc elle est la
        // même que celle de terminate() juste après.
        if ($response instanceof Response && self::measures($request, $response)) {
            $this->openVisitWindow($request, $response);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! self::measures($request, $response)) {
            return;
        }

        $lang = trim(explode(',', (string) $request->header('Accept-Language'))[0]);

        $this->client->pageview([
            'url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'lang' => $lang !== '' ? substr($lang, 0, 5) : null,
            // Ce que le NAVIGATEUR a envoyé, et non le cookie qu'on vient de
            // poser sur la réponse : `c` doit dire l'état au moment du hit.
            'visit' => self::hasVisit($request),
        ]);
    }

    /**
     * Cette requête donne-t-elle lieu à une page vue mesurée ?
     *
     * Posée aux deux phases : en réponse pour décider d'ouvrir la fenêtre de
     * visite, à terminate pour décider d'envoyer. Une seule définition, sinon
     * les deux gestes finiraient par diverger et le cookie s'écrirait sans
     * hit, ou l'inverse.
     */
    private static function measures(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET')
            || ! $response->isSuccessful()
            || $request->expectsJson()
            || $request->ajax()
        ) {
            return false;
        }

        // Le refus de la personne prime sur tout le reste : le marqueur
        // d'exclusion n'existe que pour arrêter la mesure, et on n'écrit rien
        // chez quelqu'un qui a refusé.
        if (HandleOptOut::isOptedOut($request)) {
            return false;
        }

        // Un préchargement annoncé par le navigateur n'est pas une visite : la
        // requête est réelle, la réponse aussi, mais personne ne la voit tant
        // que la navigation n'est pas confirmée. Lu sur la Request comme le
        // reste du contexte, jamais dans `$_SERVER`.
        return ! Client::announcesPrefetch(
            $request->headers->get('Sec-Purpose'),
            $request->headers->get('Purpose'),
            $request->headers->get('X-Moz'),
        );
    }

    /**
     * Une visite était-elle déjà en cours sur ce navigateur ?
     *
     * Lu sur la Request, jamais dans `$_COOKIE` : sous Octane la superglobale
     * peut appartenir à la requête précédente, et la visite d'un visiteur
     * serait recollée à celle du suivant. C'est l'erreur inverse de celle que
     * ce cookie corrige, et la plus grave des deux : deux personnes comptées
     * pour une.
     */
    private static function hasVisit(Request $request): bool
    {
        $cookie = $request->cookie(Client::VISIT_MARKER);

        return Client::hasVisit(is_string($cookie) ? $cookie : null);
    }

    /**
     * Ouvre ou prolonge la fenêtre de visite sur la réponse.
     *
     * Cookie propriétaire, `path=/`, `samesite=lax`, `secure` en https, dix
     * minutes glissantes, et jamais httpOnly : le traceur JS du même site doit
     * lire la même fenêtre, sinon le mode « les deux » en ouvrirait une
     * seconde et recompterait la personne. Sa valeur est `1` chez tout le
     * monde : elle n'identifie personne.
     */
    private function openVisitWindow(Request $request, Response $response): void
    {
        $response->headers->setCookie(Cookie::create(
            Client::VISIT_MARKER,
            '1',
            time() + Client::VISIT_LIFETIME,
            '/',
            null,
            $request->isSecure(),
            false, // httpOnly : le traceur JS doit lire la même fenêtre
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    /**
     * Le signal d'exclusion porté par l'URL, lu sur la Request.
     *
     * `$request->query()` peut rendre un tableau (`?qm_ignore[]=1`) : tout ce
     * qui n'est pas une chaîne ne dit rien plutôt que de lever une exception
     * dans le middleware d'un site en production.
     */
    private static function optOutSignal(Request $request): ?bool
    {
        $value = $request->query(Client::OPT_OUT_MARKER);

        return Client::optOutSignal(is_string($value) ? $value : null);
    }

    /**
     * La personne est-elle hors mesure pour CETTE requête ?
     *
     * Le signal de l'URL prime sur le cookie déjà posé : la page qui pose le
     * refus n'est donc pas comptée, et celle qui le retire l'est de nouveau.
     * C'est exactement ce que fait le traceur JS, qui écrit son marqueur puis
     * relit le sien avant de décider.
     */
    private static function isOptedOut(Request $request): bool
    {
        $signal = self::optOutSignal($request);
        if ($signal !== null) {
            return $signal;
        }

        $cookie = $request->cookie(Client::OPT_OUT_MARKER);

        return Client::isOptedOut(is_string($cookie) ? $cookie : null);
    }

    /**
     * Pose (ou retire) le marqueur d'exclusion sur la réponse.
     *
     * Cookie propriétaire, `path=/`, `samesite=lax`, `secure` en https, cinq
     * ans, et jamais httpOnly : le traceur JS du même site doit reconnaître ce
     * marqueur, sinon un refus posé sur une page suivie côté serveur
     * laisserait le mode script continuer de compter la même personne.
     */
    private function writeOptOutMarker(Request $request, Response $response, bool $set): void
    {
        if (! $set) {
            $response->headers->clearCookie(
                Client::OPT_OUT_MARKER,
                '/',
                null,
                $request->isSecure(),
                false,
                Cookie::SAMESITE_LAX,
            );

            return;
        }

        $response->headers->setCookie(Cookie::create(
            Client::OPT_OUT_MARKER,
            '1',
            time() + Client::OPT_OUT_LIFETIME,
            '/',
            null,
            $request->isSecure(),
            false, // httpOnly : le traceur JS doit pouvoir lire le même marqueur
            false,
            Cookie::SAMESITE_LAX,
        ));
    }
}
