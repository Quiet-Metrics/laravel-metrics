<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\ServiceProvider;
use QuietMetrics\Client;
use QuietMetrics\Laravel\Middleware\HandleOptOut;
use QuietMetrics\Laravel\Middleware\ServeSiteVerification;
use QuietMetrics\Laravel\Middleware\TrackPageview;
use QuietMetrics\Tracker;

final class QuietMetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/quiet-metrics.php', 'quiet-metrics');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            /** @var array{public_key:?string,secret_key:?string,endpoint:string,trust_proxy_headers:bool,seo_crawl?:bool} $config */
            $config = $app['config']['quiet-metrics'];

            return new Client((string) $config['public_key'], $config['secret_key'], [
                'endpoint' => $config['endpoint'],
                'trust_proxy_headers' => $config['trust_proxy_headers'],
                // `?? false` : une config publiée avant 0.6.0 n'a pas la clé.
                'seo_crawl' => (bool) ($config['seo_crawl'] ?? false),
            ]);
        });

        // Tracker est ce que le code appelant doit typer : c'est lui que
        // QuietMetrics::fake() remplace. Qui type Client en dur obtient
        // toujours le vrai client.
        $this->app->alias(Client::class, Tracker::class);
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

        // La preuve de propriété exigée par le crawl SEO, sur
        // `/.well-known/quietmetrics.json`. Poussée seulement quand le client
        // a un document à servir (`seo_crawl` activé ET clé secrète posée) :
        // par défaut rien n'est enregistré, et le chemin reste à l'application
        // (son 404, ou sa propre route).
        //
        // Middleware global et non route : une route ajoutée depuis un
        // provider est figée ou perdue par `route:cache`, et couper l'option
        // laisserait le document en ligne jusqu'à la reconstruction du cache.
        // La pile globale répond avant le routage, hors du groupe `web`, donc
        // sans session. Voir ServeSiteVerification.
        if ((bool) ($this->app['config']['quiet-metrics']['seo_crawl'] ?? false)
            && $this->app->bound(Kernel::class)
            && $this->app->make(Client::class)->siteVerificationDocument() !== null
        ) {
            $this->app->make(Kernel::class)->pushMiddleware(ServeSiteVerification::class);
        }

        // TrackPageview passe devant tous les middlewares prioritaires de la
        // route (cookies, session, authentification, limitation, liaisons).
        // Placé après eux, il ne voyait jamais dans handle() la réponse rendue
        // depuis une de leurs exceptions (liaison de modèle introuvable), alors
        // que Laravel appelait quand même son terminate() : la page vue partait
        // sans son cookie de visite, et la page suivante du même visiteur
        // passait pour une nouvelle visite. Devant eux, handle() et terminate()
        // voient toujours la même réponse.
        //
        // Il lit alors ses deux cookies avant leur déchiffrement : sans effet,
        // ils sont exclus du chiffrement (voir plus bas).
        //
        // prependToMiddlewarePriority() existe depuis Laravel 10, à la
        // différence d'addToMiddlewarePriorityBefore(). Le contrat Kernel ne le
        // déclare pas, d'où le method_exists().
        if ($this->app->bound(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);
            if (method_exists($kernel, 'prependToMiddlewarePriority')) {
                $kernel->prependToMiddlewarePriority(TrackPageview::class);
            }
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
