# laboiteacode/webanalytics-laravel — pont Laravel

Intégration Laravel du [package cœur PHP](../php) : pageviews serveur automatiques, facade et configuration. **Le tracking se fait côté serveur** — sans cookie, sans JS, imblocable par les adblockers.

## Installation (sur le site du client)

```bash
composer require laboiteacode/webanalytics-laravel
php artisan vendor:publish --tag=webanalytics-config
```

```dotenv
WEBANALYTICS_PUBLIC_KEY=wa_pub_xxxx
WEBANALYTICS_SECRET_KEY=wa_sec_xxxx
```

## Usage

```php
// Pageviews automatiques (envoi en terminate(), zéro latence perçue) :
Route::middleware('webanalytics')->group(function () {
    // … vos routes web
});

// Événements personnalisés, où vous voulez :
use LaBoiteACode\WebAnalytics\Laravel\Facades\WebAnalytics;

WebAnalytics::event('achat', ['montant' => 49]);

// Ou par injection de LaBoiteACode\WebAnalytics\Client.
```

Le middleware ne compte que les `GET` HTML réussis (pas d'AJAX/JSON). Les vues d'erreur, redirections et requêtes API sont ignorées ; affinez avec vos propres conditions en l'étendant.

## Tests

```bash
composer update && composer test
```

4 tests Orchestra Testbench contre le serveur de capture HTTP du cœur : pageview middleware signée (URL/UA/langue/référent relayés), exclusions (JSON, POST, erreurs), facade `event`, singleton configuré. Le contexte vient de l'objet `Request` (jamais des superglobales) : correct sous Octane et dans les tests.

## Installer en local (avant la publication Packagist)

Depuis un projet Laravel sur la même machine — les **deux** path repositories sont nécessaires (le pont dépend du cœur en `@dev`) :

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

Validé de bout en bout : app Laravel vierge → `composer require` → middleware + facade → hits signés reçus, sessions et rollups calculés côté plateforme.

## Reste à faire avant v1

- [x] Tests (Orchestra Testbench) : provider, middleware, facade, config.
- [ ] Option `exclude_paths` dans la config (motifs ignorés par le middleware).
- [ ] Helper Blade `@webanalytics` (snippet JS pré-rempli) pour ceux qui veulent le mode navigateur en complément.
