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
 */
final class TrackPageview
{
    public function __construct(private readonly Client $client)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethod('GET')
            && $response->isSuccessful()
            && !$request->expectsJson()
            && !$request->ajax()
        ) {
            $this->client->pageview();
        }
    }
}
