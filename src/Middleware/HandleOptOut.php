<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use QuietMetrics\Client;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le marqueur d'exclusion, posé ou retiré à la demande de la personne.
 *
 * VOLONTAIREMENT SÉPARÉ de TrackPageview, et poussé dans le groupe `web`
 * plutôt que laissé au choix de la route. Les deux ont d'abord vécu ensemble,
 * et c'était un défaut : TrackPageview s'applique route par route, si bien
 * qu'une application qui n'envoie que des événements manuels, ou qui ne trace
 * qu'une partie de ses pages, laissait `?qm_ignore=1` sans effet. La LECTURE
 * du refus continuait pourtant de fonctionner, le SDK cœur lisant le cookie :
 * un visiteur restait donc exclu s'il s'était retiré ailleurs, mais ne
 * pouvait plus le faire ici. Un mécanisme de refus ne dépend pas d'une option
 * de mesure.
 *
 * Le contexte vient de l'objet Request, jamais des superglobales : correct
 * sous Octane et tout worker persistant, où `$_GET` peut appartenir à une
 * requête précédente.
 */
final class HandleOptOut
{
    public function handle(Request $request, Closure $next): mixed
    {
        $signal = self::signal($request);

        /** @var Response $response */
        $response = $next($request);

        if ($signal === null) {
            return $response;
        }

        if (! $signal) {
            $response->headers->clearCookie(
                Client::OPT_OUT_MARKER,
                '/',
                null,
                $request->isSecure(),
                false,
                Cookie::SAMESITE_LAX,
            );

            return $response;
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

        return $response;
    }

    /** Le signal d'exclusion porté par l'URL, lu sur la Request. */
    public static function signal(Request $request): ?bool
    {
        $value = $request->query(Client::OPT_OUT_MARKER);

        return Client::optOutSignal(is_string($value) ? $value : null);
    }

    /**
     * La personne est-elle hors mesure pour CETTE requête ?
     *
     * Le signal de l'URL prime sur le cookie déjà posé : la page qui pose le
     * refus n'est donc pas comptée, et celle qui le retire l'est de nouveau.
     */
    public static function isOptedOut(Request $request): bool
    {
        $signal = self::signal($request);
        if ($signal !== null) {
            return $signal;
        }

        $cookie = $request->cookie(Client::OPT_OUT_MARKER);

        return Client::isOptedOut(is_string($cookie) ? $cookie : null);
    }
}
