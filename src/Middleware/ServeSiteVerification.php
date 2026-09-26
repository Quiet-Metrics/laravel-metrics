<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use QuietMetrics\Client;

/**
 * La preuve de propriété du site, servie sur `/.well-known/quietmetrics.json`.
 *
 * L'onglet SEO de Quiet Metrics n'explore que les sites qui prouvent
 * appartenir au compte qui les a déclarés. Le document (voir
 * Client::siteVerificationDocument()) porte un HMAC de la clé secrète : seul
 * le détenteur de cette clé peut le produire.
 *
 * Middleware GLOBAL plutôt que route, poussé par le provider seulement quand
 * `seo_crawl` est activé et qu'une clé secrète existe. Une route déclarée
 * depuis un provider se heurte au cache des routes : `route:cache` la
 * figerait, ou la perdrait selon l'ordre de chargement, et couper l'option
 * laisserait alors le document en ligne jusqu'à la prochaine reconstruction
 * du cache. Placé dans la pile globale, il répond avant le routage, hors du
 * groupe `web` : ni session, ni cookie chiffré, ni middleware de mesure (la
 * réponse n'est pas une page HTML, elle ne serait de toute façon pas comptée).
 *
 * Rien d'autre n'est intercepté : toute autre requête, ou un document null,
 * continue vers l'application comme si le SDK n'existait pas.
 */
final class ServeSiteVerification
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! self::targets($request)) {
            return $next($request);
        }

        // Relu à chaque requête et pas seulement au démarrage : sous Octane
        // le provider ne repasse pas, et ce qui décide reste le client.
        $document = $this->client->siteVerificationDocument();
        if ($document === null) {
            return $next($request);
        }

        // Pour un HEAD, Symfony retire lui-même le corps en préparant la réponse.
        return new Response($document, 200, [
            'Content-Type' => 'application/json',
            // Jamais mis en cache : couper l'option ou changer de clé doit
            // prendre effet au prochain passage de la plateforme.
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Un GET ou un HEAD sur le chemin exact, la chaîne de requête mise à part. */
    public static function targets(Request $request): bool
    {
        return ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && $request->getPathInfo() === Client::SITE_VERIFICATION_PATH;
    }
}
