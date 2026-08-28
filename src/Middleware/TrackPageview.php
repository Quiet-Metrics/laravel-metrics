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

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET')
            || ! $response->isSuccessful()
            || $request->expectsJson()
            || $request->ajax()
        ) {
            return;
        }

        // Le refus de la personne prime sur tout le reste : le marqueur
        // d'exclusion n'existe que pour arrêter la mesure.
        if (HandleOptOut::isOptedOut($request)) {
            return;
        }

        // Un préchargement annoncé par le navigateur n'est pas une visite : la
        // requête est réelle, la réponse aussi, mais personne ne la voit tant
        // que la navigation n'est pas confirmée. Lu sur la Request comme le
        // reste du contexte, jamais dans `$_SERVER`.
        if (Client::announcesPrefetch(
            $request->headers->get('Sec-Purpose'),
            $request->headers->get('Purpose'),
            $request->headers->get('X-Moz'),
        )) {
            return;
        }

        $lang = trim(explode(',', (string) $request->header('Accept-Language'))[0]);

        $this->client->pageview([
            'url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'lang' => $lang !== '' ? substr($lang, 0, 5) : null,
        ]);
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
