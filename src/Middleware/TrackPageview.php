<?php

declare(strict_types=1);

namespace LaBoiteACode\WebAnalytics\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaBoiteACode\WebAnalytics\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pageview serveur automatique — imblocable par les adblockers, zéro JS.
 * L'envoi a lieu dans terminate(), après l'envoi de la réponse au visiteur :
 * aucun impact sur la latence perçue.
 *
 * Le contexte vient de l'objet Request (et non des superglobales) : correct
 * sous Octane/workers persistants, dans les tests, et aligné sur les
 * trusted proxies configurés dans l'application hôte.
 */
final class TrackPageview
{
    public function __construct(private readonly Client $client) {}

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
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

        $lang = trim(explode(',', (string) $request->header('Accept-Language'))[0]);

        $this->client->pageview([
            'url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'lang' => $lang !== '' ? substr($lang, 0, 5) : null,
        ]);
    }
}
