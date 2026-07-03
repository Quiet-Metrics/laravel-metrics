<?php

declare(strict_types=1);

namespace LaBoiteACode\WebAnalytics\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LaBoiteACode\WebAnalytics\Client;
use LaBoiteACode\WebAnalytics\Laravel\Middleware\TrackPageview;

final class WebAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/webanalytics.php', 'webanalytics');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var array{public_key:?string,secret_key:?string,endpoint:string,trust_proxy_headers:bool} $config */
            $config = $app['config']['webanalytics'];

            return new Client((string) $config['public_key'], $config['secret_key'], [
                'endpoint' => $config['endpoint'],
                'trust_proxy_headers' => $config['trust_proxy_headers'],
            ]);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/webanalytics.php' => config_path('webanalytics.php'),
        ], 'webanalytics-config');

        // Route::middleware('webanalytics') → pageviews serveur automatiques.
        $this->app['router']->aliasMiddleware('webanalytics', TrackPageview::class);
    }
}
