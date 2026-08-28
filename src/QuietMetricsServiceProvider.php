<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\ServiceProvider;
use QuietMetrics\Client;
use QuietMetrics\Laravel\Middleware\TrackPageview;

final class QuietMetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/quiet-metrics.php', 'quiet-metrics');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var array{public_key:?string,secret_key:?string,endpoint:string,trust_proxy_headers:bool} $config */
            $config = $app['config']['quiet-metrics'];

            return new Client((string) $config['public_key'], $config['secret_key'], [
                'endpoint' => $config['endpoint'],
                'trust_proxy_headers' => $config['trust_proxy_headers'],
            ]);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/quiet-metrics.php' => config_path('quiet-metrics.php'),
        ], 'quiet-metrics-config');

        // Route::middleware('quiet-metrics') → pageviews serveur automatiques.
        $this->app['router']->aliasMiddleware('quiet-metrics', TrackPageview::class);

        // Le marqueur d'exclusion échappe au chiffrement des cookies de
        // Laravel. Chiffré, il ne vaudrait plus « 1 » chez le visiteur : le
        // traceur JS du même site ne reconnaîtrait plus le refus, et le mode
        // « les deux » continuerait de mesurer une personne qui s'en est
        // exclue. Il ne contient rien à protéger, c'est une valeur constante
        // qui dit « ne me compte pas ». Le test class_exists garde le cas d'un
        // hôte qui n'installe qu'illuminate/support.
        if (class_exists(EncryptCookies::class)) {
            EncryptCookies::except(Client::OPT_OUT_MARKER);
        }
    }
}
