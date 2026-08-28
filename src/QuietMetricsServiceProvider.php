<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\ServiceProvider;
use QuietMetrics\Client;
use QuietMetrics\Laravel\Middleware\HandleOptOut;
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
        $this->app['router']->aliasMiddleware('quiet-metrics-optout', HandleOptOut::class);

        // Le marqueur d'exclusion est pousse dans le groupe `web`, et non
        // laisse au choix de la route. TrackPageview s'applique route par
        // route : une application qui n'envoie que des evenements manuels, ou
        // qui ne trace qu'une partie de ses pages, laissait `?qm_ignore=1`
        // sans effet, alors que la LECTURE du refus, elle, fonctionnait. Un
        // visiteur restait donc exclu s'il s'etait retire ailleurs mais ne
        // pouvait plus le faire ici. Un mecanisme de refus ne depend pas d'une
        // option de mesure, et le bundle Symfony enregistre son listener selon
        // la meme regle.
        //
        // `register_opt_out_middleware` permet de le retirer, pour une
        // application qui gere le marqueur elle-meme ou dont le groupe `web`
        // ne couvre pas les pages mesurees.
        // Pousse dans la pile GLOBALE du kernel et non dans le groupe `web`.
        // `pushMiddlewareToGroup('web', ...)` depuis un provider ne prend pas
        // effet sous Laravel 11+, ou les groupes sont construits par le
        // bootstrap de l'application : verifie, le middleware apparaissait bien
        // dans `getMiddlewareGroups()` mais son `handle()` n'etait jamais
        // appele. Un mecanisme de refus qui ne s'execute pas est pire que pas
        // de mecanisme, puisqu'on le croit en place.
        //
        // Global est d'ailleurs la bonne portee ici : le refus doit pouvoir se
        // poser depuis n'importe quelle URL du site, pas seulement depuis les
        // routes `web`. Le middleware ne fait rien sans le parametre.
        if ((bool) ($this->app['config']['quiet-metrics']['register_opt_out_middleware'] ?? true)
            && $this->app->bound(Kernel::class)
        ) {
            $this->app->make(Kernel::class)->pushMiddleware(HandleOptOut::class);
        }

        // Les deux cookies échappent au chiffrement des cookies de Laravel.
        // Chiffrés, ils ne vaudraient plus « 1 » chez le visiteur : le traceur
        // JS du même site ne les reconnaîtrait pas, et le mode « les deux »
        // continuerait de mesurer une personne qui s'en est exclue, ou
        // ouvrirait une seconde fenêtre de visite pour la même personne.
        // Ni l'un ni l'autre ne contient quoi que ce soit à protéger : leur
        // valeur est la même chez tout le monde. Le test class_exists garde le
        // cas d'un hôte qui n'installe qu'illuminate/support.
        if (class_exists(EncryptCookies::class)) {
            EncryptCookies::except(Client::OPT_OUT_MARKER);
            EncryptCookies::except(Client::VISIT_MARKER);
        }
    }
}
