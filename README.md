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

## Reste à faire avant v1

- [ ] Tests (Orchestra Testbench) : provider, middleware, facade, config.
- [ ] Option `exclude_paths` dans la config (motifs ignorés par le middleware).
- [ ] Helper Blade `@webanalytics` (snippet JS pré-rempli) pour ceux qui veulent le mode navigateur en complément.
