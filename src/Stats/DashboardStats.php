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
 * Le résultat porte un `status` : `ok`, `not_configured`, `insecure` (adresse
 * du service hors https), `rejected` (401 ou 404 : jeton invalide, expiré,
 * révoqué, ou clé publique d'un autre compte), `forbidden` (403 : plan sans
 * l'API, compte fermé, ou site que le jeton ne couvre pas) ou `unavailable`.
 * Jamais le corps d'une réponse en erreur : il n'a rien à faire dans un
 * back-office.
 */
final class DashboardStats
{
    /** Dix minutes : l'API compte 120 requêtes par minute et par jeton. */
    public const CACHE_SECONDS = 600;

    /**
     * Un échec est gardé une minute : un service en panne ou un 429 ne doit
     * pas coûter cinq secondes de délai à chaque affichage du tableau de bord.
     */
    public const FAILURE_CACHE_SECONDS = 60;

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
        if (! self::isSecure($origin)) {
            return ['status' => 'insecure'];
        }

        // Empreinte et non jeton en clair : un cache partagé ou un dump de
        // Redis ne doit pas suffire à le lire.
        $key = 'quiet-metrics:widget:'.hash('sha256', implode('|', [$origin, $publicKey, $token, $period]));

        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch($origin, $publicKey, $token, $period);

        $this->cache->put($key, $result, $result['status'] === 'ok' ? self::CACHE_SECONDS : self::FAILURE_CACHE_SECONDS);

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
     * Le jeton ne part jamais en clair : https obligatoire, sauf vers la
     * machine elle-même (développement local).
     */
    public static function isSecure(string $origin): bool
    {
        $parts = parse_url($origin);

        return ($parts['scheme'] ?? '') === 'https' || in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);
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
                // Pas de redirection suivie : elle emporterait l'en-tête du jeton ailleurs.
                $response = Http::withToken($token)->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)
                    ->get($base.'/'.$endpoint, $query);
            } catch (Throwable) {
                return ['status' => 'unavailable'];
            }

            if (in_array($response->status(), [401, 404], true)) {
                return ['status' => 'rejected'];
            }
            if ($response->status() === 403) {
                return ['status' => 'forbidden'];
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
        // Une réponse 200 malformée vaut une panne, pas une exception de vue.
        $from = $summary['period']['from'] ?? null;
        $to = $summary['period']['to'] ?? null;
        if (! self::isDate($from) || ! self::isDate($to)) {
            return ['status' => 'unavailable'];
        }

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
            if (is_string($row['label'] ?? null)) {
                $pages[] = ['label' => $row['label'], 'value' => (int) ($row['value'] ?? 0)];
            }
        }

        return [
            'status' => 'ok',
            'from' => $from,
            'to' => $to,
            'kpis' => $kpis,
            'trend' => array_map(static fn ($point): int => (int) ($point['visitors'] ?? 0), (array) ($timeseries['data'] ?? [])),
            'pages' => $pages,
            'capped' => (bool) ($breakdown['capped']['any'] ?? false),
            'url' => $origin.'/app',
        ];
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
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
