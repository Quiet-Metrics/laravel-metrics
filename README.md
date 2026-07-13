# laboiteacode/webanalytics-laravel

Pont Laravel d'[Affluence](https://app.affluence.fr) (La Boîte à Code) : pageviews serveur automatiques via middleware, facade pour les événements, configuration publiable. Le tracking se fait à 100 % côté serveur, sans cookie, sans JS, invisible pour les adblockers. Repose sur le [package cœur PHP](../php) (`laboiteacode/webanalytics-php`).

Compatible Laravel 10 à 13 (illuminate/support ^10 || ^11 || ^12 || ^13), PHP >= 8.1.

## Installation

```bash
composer require laboiteacode/webanalytics-laravel
```

Le service provider et l'alias de facade sont enregistrés automatiquement (package discovery).

Avant la publication sur Packagist (développement en monorepo), déclarez les **deux** path repositories depuis le projet hôte, le pont dépend du cœur :

```json
{
    "repositories": [
        { "type": "path", "url": "../WebAnalytics/packages/php", "options": { "symlink": true } },
        { "type": "path", "url": "../WebAnalytics/packages/laravel", "options": { "symlink": true } }
    ]
}
```

```bash
composer require laboiteacode/webanalytics-laravel:@dev
```

## Configuration

Publiez le fichier de configuration (optionnel, les variables d'environnement suffisent dans la plupart des cas) :

```bash
php artisan vendor:publish --tag=webanalytics-config
```

Variables d'environnement :

```dotenv
# Clés du site, panneau « Installation » du tableau de bord Affluence.
WEBANALYTICS_PUBLIC_KEY=wa_pub_xxxx
# Optionnelle mais recommandée : active le mode signé (HMAC), l'IP/UA
# du visiteur transmis par votre serveur font alors foi.
WEBANALYTICS_SECRET_KEY=wa_sec_xxxx

# Facultatives :
WEBANALYTICS_ENDPOINT=https://app.affluence.fr/api/v1/collect
WEBANALYTICS_TRUST_PROXY=false   # true si l'app est derrière un reverse proxy / CDN
```

## Usage

### Middleware : pageviews automatiques

Le middleware est enregistré sous l'alias `webanalytics`. Par route ou par groupe :

```php
Route::middleware('webanalytics')->group(function () {
    // ... vos routes web
});
```

En global sur tout le groupe web, Laravel 11+ (`bootstrap/app.php`) :

```php
use LaBoiteACode\WebAnalytics\Laravel\Middleware\TrackPageview;

->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: TrackPageview::class);
})
```

Laravel 10 (`app/Http/Kernel.php`) :

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \LaBoiteACode\WebAnalytics\Laravel\Middleware\TrackPageview::class,
    ],
];
```

Le middleware ne compte que les `GET` HTML réussis : les requêtes non GET, les réponses non 2xx et les requêtes AJAX/JSON sont ignorées.

### Facade : événements personnalisés

```php
use LaBoiteACode\WebAnalytics\Laravel\Facades\WebAnalytics;

// Événement avec propriétés (valeurs scalaires, 30 clés max).
WebAnalytics::event('inscription', ['plan' => 'pro']);

// Pageview manuelle, contexte surchargeable (utile hors requête HTTP :
// jobs, commandes artisan ; `url` est alors obligatoire).
WebAnalytics::pageview(['url' => 'https://monsite.fr/tarifs']);
```

Vous pouvez aussi injecter directement `LaBoiteACode\WebAnalytics\Client` (enregistré en singleton) plutôt que passer par la facade.

## Comment ça marche

Le provider construit un singleton `Client` (package cœur) à partir de la config `webanalytics`. Le middleware envoie la pageview dans `terminate()`, après l'envoi de la réponse au visiteur : aucun impact sur la latence perçue. Le contexte (URL, referrer, IP, User-Agent, langue) vient de l'objet `Request` et non des superglobales : correct sous Octane et workers persistants, dans les tests, et aligné sur les trusted proxies de l'application hôte. Côté cœur, l'envoi est non bloquant (socket fire-and-forget, repli cURL court) et tout échec est silencieux : l'analytics ne casse jamais le site.

## Tests

```bash
composer update && composer test
```

Suite Orchestra Testbench contre le serveur de capture HTTP du package cœur : pageview middleware signée (HMAC vérifiée), exclusions (JSON, POST, erreurs), facade `event`, singleton configuré.

## Licence

MIT.
