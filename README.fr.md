# quiet-metrics/laravel-metrics

![Quiet Metrics : pont Laravel](art/banner.png)

> 🇬🇧 [English version](README.md)

Pont Laravel de [Quiet Metrics](https://quietmetrics.dev) (La Boîte à Code) : pageviews serveur automatiques via middleware, facade pour les événements, configuration publiable. Le tracking se fait à 100 % côté serveur, sans cookie, sans JS, invisible pour les adblockers. Repose sur le [package cœur PHP](https://github.com/Quiet-Metrics/php-metrics) (`quiet-metrics/php-metrics`).

Compatible Laravel 10 à 13 (illuminate/support ^10 || ^11 || ^12 || ^13), PHP >= 8.1.

## Installation

```bash
composer require quiet-metrics/laravel-metrics
```

Le service provider et l'alias de facade sont enregistrés automatiquement (package discovery).

## Configuration

Publiez le fichier de configuration (optionnel, les variables d'environnement suffisent dans la plupart des cas) :

```bash
php artisan vendor:publish --tag=quiet-metrics-config
```

Variables d'environnement :

```dotenv
# Clés du site, panneau « Installation » du tableau de bord Quiet Metrics.
QUIET_METRICS_PUBLIC_KEY=qm_pub_xxxx
# INDISPENSABLE en envoi serveur : active le mode signé (HMAC), seul cas où
# l'IP/UA du visiteur transmis par votre serveur font foi. Sans elle, tous
# les hits porteraient l'IP de votre serveur : un seul visiteur compté.
QUIET_METRICS_SECRET_KEY=qm_sec_xxxx

# Facultatives :
QUIET_METRICS_ENDPOINT=https://quietmetrics.dev/api/v1/collect
QUIET_METRICS_TRUST_PROXY=false   # true si l'app est derrière un reverse proxy / CDN
```

## Usage

### Middleware : pageviews automatiques

Le middleware est enregistré sous l'alias `quiet-metrics`. Par route ou par groupe :

```php
Route::middleware('quiet-metrics')->group(function () {
    // ... vos routes web
});
```

En global sur tout le groupe web, Laravel 11+ (`bootstrap/app.php`) :

```php
use QuietMetrics\Laravel\Middleware\TrackPageview;

->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: TrackPageview::class);
})
```

Laravel 10 (`app/Http/Kernel.php`) :

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \QuietMetrics\Laravel\Middleware\TrackPageview::class,
    ],
];
```

Le middleware ne compte que les `GET` HTML réussis : les requêtes non GET, les réponses non 2xx et les requêtes AJAX/JSON sont ignorées.

### Facade : événements personnalisés

```php
use QuietMetrics\Laravel\Facades\QuietMetrics;

// Événement avec propriétés (valeurs scalaires, 30 clés max).
QuietMetrics::event('inscription', ['plan' => 'pro']);

// Pageview manuelle, contexte surchargeable (utile hors requête HTTP :
// jobs, commandes artisan ; `url` est alors obligatoire).
QuietMetrics::pageview(['url' => 'https://monsite.fr/tarifs']);
```

Vous pouvez aussi injecter directement `QuietMetrics\Client` (enregistré en singleton) plutôt que passer par la facade.

## Comment ça marche

Le provider construit un singleton `Client` (package cœur) à partir de la config `quiet-metrics`. Le middleware envoie la pageview dans `terminate()`, après l'envoi de la réponse au visiteur : aucun impact sur la latence perçue. Le contexte (URL, referrer, IP, User-Agent, langue) vient de l'objet `Request` et non des superglobales : correct sous Octane et workers persistants, dans les tests, et aligné sur les trusted proxies de l'application hôte. Côté cœur, l'envoi est non bloquant (socket fire-and-forget, repli cURL court) et tout échec est silencieux : l'analytics ne casse jamais le site.

## Tests

```bash
composer update && composer test
```

Suite Orchestra Testbench contre le serveur de capture HTTP du package cœur : pageview middleware signée (HMAC vérifiée), exclusions (JSON, POST, erreurs), facade `event`, singleton configuré.

## Licence

MIT. Un produit [La Boîte à Code](https://laboiteacode.fr) pour [Quiet Metrics](https://quietmetrics.dev).
