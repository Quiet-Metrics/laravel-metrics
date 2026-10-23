<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Stats;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Les chiffres du widget de back-office, lus sur l'API de lecture.
 *
 * Tout se passe côté serveur : le jeton de lecture vient de la configuration
 * et ne quitte jamais le serveur, ni dans la page ni dans la clé de cache.
 * Le résultat porte un `status` : `ok`, `not_configured`, `rejected` (jeton
 * refusé, expiré, ou qui ne couvre pas ce site) ou `unavailable`. Jamais le
 * corps d'une réponse en erreur : il n'a rien à faire dans un back-office.
 */
final class DashboardStats
{
    /** Dix minutes : l'API compte 120 requêtes par minute et par jeton. */
    public const CACHE_SECONDS = 600;

    public function __construct(private readonly Config $config, private readonly Cache $cache)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $period = '30d'): array
    {
        $publicKey = (string) $this->config->get('quiet-metrics.public_key');
        $token = (string) $this->config->get('quiet-metrics.read_token');
        $origin = $this->origin();

        if ($publicKey === '' || $token === '' || $origin === null) {
            return ['status' => 'not_configured'];
        }

        // Empreinte et non jeton en clair : un cache partagé ou un dump de
        // Redis ne doit pas suffire à le lire.
        $key = 'quiet-metrics:widget:'.hash('sha256', implode('|', [$origin, $publicKey, $token, $period]));

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch($origin, $publicKey, $token, $period);

        // Seul un succès est gardé : une erreur se retente au prochain affichage.
        if ($result['status'] === 'ok') {
            $this->cache->put($key, $result, self::CACHE_SECONDS);
        }

        return $result;
    }

    /**
     * Origine de la plateforme, déduite du point de collecte déjà configuré :
     * l'API de lecture vit sur le même hôte.
     */
    public function origin(): ?string
    {
        $parts = parse_url((string) $this->config->get('quiet-metrics.endpoint'));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $origin, string $publicKey, string $token, string $period): array
    {
        $base = $origin.'/api/v1/stats/'.rawurlencode($publicKey);
        $queries = [
            'summary' => ['period' => $period],
            'timeseries' => ['period' => $period, 'granularity' => 'day'],
            'breakdown' => ['period' => $period, 'dimension' => 'page', 'limit' => 5],
        ];

        $bodies = [];
        foreach ($queries as $endpoint => $query) {
            try {
                $response = Http::withToken($token)->acceptJson()->connectTimeout(3)->timeout(5)
                    ->get($base.'/'.$endpoint, $query);
            } catch (Throwable) {
                return ['status' => 'unavailable'];
            }

            if (in_array($response->status(), [401, 403, 404], true)) {
                return ['status' => 'rejected'];
            }
            $body = $response->json();
            if (! $response->successful() || ! is_array($body)) {
                return ['status' => 'unavailable'];
            }
            $bodies[$endpoint] = $body;
        }

        return $this->shape($bodies['summary'], $bodies['timeseries'], $bodies['breakdown'], $origin);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $timeseries
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>
     */
    private function shape(array $summary, array $timeseries, array $breakdown, string $origin): array
    {
        // Une fenêtre précédente non mesurée n'est pas une base de comparaison
        // (docs de l'API, `comparison.measured`).
        $measured = ($summary['comparison']['measured'] ?? true) !== false;

        $kpis = [];
        foreach (['visitors', 'pageviews', 'sessions'] as $metric) {
            $current = $summary['kpis'][$metric] ?? null;
            $previous = $measured ? ($summary['previous'][$metric] ?? null) : null;
            $kpis[$metric] = [
                'value' => is_numeric($current) ? (int) $current : null,
                'change' => self::change($current, $previous),
            ];
        }

        $pages = [];
        foreach ((array) ($breakdown['data'] ?? []) as $row) {
            $pages[] = ['label' => $row['label'] ?? null, 'value' => (int) ($row['value'] ?? 0)];
        }

        return [
            'status' => 'ok',
            'from' => (string) ($summary['period']['from'] ?? ''),
            'to' => (string) ($summary['period']['to'] ?? ''),
            'kpis' => $kpis,
            'trend' => array_map(static fn ($point): int => (int) ($point['visitors'] ?? 0), (array) ($timeseries['data'] ?? [])),
            'pages' => $pages,
            'capped' => (bool) ($breakdown['capped']['any'] ?? false),
            'url' => $origin.'/app',
        ];
    }

    /**
     * Évolution en pourcentage entier, ou null quand il n'y a rien à comparer :
     * précédent absent (null) ou nul. Jamais un -100 % fabriqué depuis un vide.
     */
    public static function change(mixed $current, mixed $previous): ?int
    {
        if (! is_numeric($current) || ! is_numeric($previous) || (float) $previous == 0.0) {
            return null;
        }

        return (int) round(((float) $current - (float) $previous) / (float) $previous * 100);
    }
}
