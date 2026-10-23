# quiet-metrics/laravel-metrics

![Quiet Metrics : pont Laravel](art/banner.png)

> 🇬🇧 [English version](README.md)

Pont Laravel de [Quiet Metrics](https://quietmetrics.dev) (QuietSystems) : pageviews serveur automatiques via middleware, facade pour les événements, configuration publiable. Le tracking se fait à 100 % côté serveur, sans JS et sans cookie d'identification ni de traçabilité, invisible pour les adblockers. Repose sur le [package cœur PHP](https://github.com/Quiet-Metrics/php-metrics) (`quiet-metrics/php-metrics`).

Compatible Laravel 10 à 13 (illuminate/support ^10 || ^11 || ^12 || ^13), PHP >= 8.1.

## Installation

```bash
composer require quiet-metrics/laravel-metrics
```

Le service provider et l'alias de facade sont enregistrés automatiquement (package discovery).

## Mettre à jour

Chaque version mineure 0.x du pont exige la version mineure correspondante du SDK cœur `quiet-metrics/php-metrics` : montez les deux ensemble, et eux seuls.

```bash
composer require quiet-metrics/laravel-metrics:^0.6 --no-update
composer update quiet-metrics/laravel-metrics quiet-metrics/php-metrics
```

Un simple `composer require` échoue, car le SDK cœur est verrouillé dans `composer.lock`. Le message d'erreur de Composer propose alors `-W` : cette option met à jour toutes les dépendances du projet, Laravel compris. Lisez le [CHANGELOG](CHANGELOG.md) avant de monter : il signale les changements dans ce qui est compté.

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
QUIET_METRICS_SEO_CRAWL=false     # true autorise l'onglet SEO de Quiet Metrics à explorer le site (voir plus bas)
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

Les pages vues automatiques sont les documents HTML/XHTML affichés en réponse à un `GET`, y compris les erreurs 404/500 levées par une route de votre application (`abort(404)`, modèle introuvable). Les redirections, réponses vides (204/205), PDF, JSON et pièces jointes sont exclus, ainsi que l’AJAX, les préchargements annoncés et les visiteurs exclus.

**Les adresses qui ne correspondent à aucune route ne sont pas mesurées** par l'intégration dans le groupe `web` : quand aucune route ne correspond, Laravel n'exécute aucun middleware de groupe. Ces 404 comprennent les liens cassés et les anciennes adresses, mais aussi la plupart des requêtes de robots qui sondent les sites (`/wp-login.php`, `/.env`). Pour les mesurer quand même, déclarez le middleware dans la pile globale, **à la place** du groupe `web` : `$middleware->append(TrackPageview::class)` en Laravel 11+, le tableau `$middleware` de `app/Http/Kernel.php` en Laravel 10. La pile globale couvre aussi les routes hors du groupe `web`, comme un panneau d'administration : écartez-les avec les chemins exclus dans les réglages du site. Ne déclarez pas le middleware aux deux endroits, sinon chaque page serait comptée deux fois.

À partir de la version **0.4.0**, `'track_404' => true` active en plus un événement `404` avec le chemin de la page, sur les seules 404 que le middleware voit. Cette option est désactivée par défaut : chaque événement supplémentaire consomme le quota. Activez-la côté serveur ou via `data-404` dans le script sur une même page, pour éviter un double événement. Le suivi ne voit pas les pages servies par un cache qui contourne PHP.

### Facade : événements personnalisés

```php
use QuietMetrics\Laravel\Facades\QuietMetrics;

// Événement avec propriétés (valeurs scalaires, 30 clés max).
QuietMetrics::event('inscription', ['plan' => 'pro']);

// Pageview manuelle, contexte surchargeable (utile hors requête HTTP :
// jobs, commandes artisan ; `url` est alors obligatoire).
QuietMetrics::pageview(['url' => 'https://monsite.fr/tarifs']);
```

Vous pouvez aussi injecter `QuietMetrics\Tracker` (l'interface qu'implémente le singleton `Client`) plutôt que passer par la facade : typer l'interface, c'est ce qui rend votre code remplaçable en test.

### Dans vos tests

À partir de la version 0.5.0, `QuietMetrics::fake()` remplace le client par un faux qui enregistre au lieu d'envoyer. Les pages vues du middleware y passent aussi.

```php
use QuietMetrics\Laravel\Facades\QuietMetrics;

QuietMetrics::fake();

$this->post('/register', [...])->assertRedirect();

QuietMetrics::assertEventSent('inscription', ['plan' => 'pro']);
QuietMetrics::assertPageviewSent();
```

Autres assertions : `assertEventNotSent('nom')` et `assertNothingSent()`. Le faux enregistre ce que votre application demande, avant la minimisation faite à l'envoi.

## S'exclure de la mesure

Un visiteur peut demander à ne plus être compté, sans compte et sans écrire à personne : il visite une page de votre site avec `?qm_ignore=1`, et `?qm_ignore=0` le remet dans la mesure.

```
https://monsite.fr/?qm_ignore=1     ne plus être compté
https://monsite.fr/?qm_ignore=0     être compté à nouveau
```

Le marqueur est un **cookie propriétaire de votre site**, nommé `qm_ignore` et valant `1` (`path=/`, `samesite=lax`, `secure` en https, cinq ans). Un middleware dédié, `HandleOptOut`, s'en charge, et il est enregistré globalement par le service provider : le marqueur se pose donc depuis **n'importe quelle URL**, et pas seulement depuis les routes que vous tracez. Rien à câbler. Pour le gérer vous-même, passez `quiet-metrics.register_opt_out_middleware` à `false` : l'alias `quiet-metrics-optout` reste disponible.

Il ne contient aucun identifiant (sa valeur est la même chez tout le monde), il n'est jamais transmis à Quiet Metrics, et il n'existe que pour arrêter la mesure : c'est un marqueur de refus, pas un traceur. Le tracker JS écrit en plus la même valeur en `localStorage`, mais un SDK serveur ne lit que le cookie : une seule visite suffit donc pour les deux modes de suivi.

## Continuité de visite

Quand l'empreinte visiteur change en cours de visite (4G puis wifi), la même personne compterait sinon pour deux visiteurs uniques le même jour. Un second **cookie propriétaire de votre site** ferme cet écart : `qm_visit`, valant `1` (`path=/`, `samesite=lax`, `secure` en https), sur une fenêtre glissante de dix minutes repoussée à chaque hit mesuré. Chaque hit reporte dans la clé `c` du payload s'il était déjà là.

Sa valeur est constante, la même chez tout le monde : elle n'identifie personne, elle dit seulement qu'une visite est déjà en cours sur ce navigateur. Il n'est jamais écrit chez quelqu'un qui a posé le marqueur d'exclusion, ni quand rien n'est mesuré. Le middleware `quiet-metrics` l'écrit pendant la phase réponse, sur les requêtes dont il envoie la page vue dans `terminate()`. Comme le marqueur d'exclusion, il échappe au chiffrement des cookies de Laravel : le traceur JS du même site doit lire la même fenêtre.

À savoir si votre site est mis en cache : une réponse mesurée porte désormais un en-tête `Set-Cookie`, que certains reverse proxys et CDN prennent comme une raison de ne pas stocker la réponse.

## Crawl SEO

L'onglet SEO de Quiet Metrics n'explore qu'un site qui prouve appartenir au compte qui l'a déclaré. Posez les deux variables et le pont sert cette preuve automatiquement sur `/.well-known/quietmetrics.json` :

```dotenv
QUIET_METRICS_SECRET_KEY=qm_sec_xxxx
QUIET_METRICS_SEO_CRAWL=true
```

Le document vaut `{"site_verification":["<jeton>"]}`, où le jeton est un HMAC-SHA256 calculé avec votre clé **secrète** (jamais la clé elle-même ; la clé publique ne conviendrait pas, puisqu'elle se lit dans le HTML de vos pages). Il ne répond qu'à `GET` et `HEAD`, avec `Content-Type: application/json` et `Cache-Control: no-store`.

Éteint par défaut. Sans `QUIET_METRICS_SEO_CRAWL=true`, ou sans clé secrète, rien n'est enregistré : le chemin appartient à votre application (son 404, ou sa propre route), et aucun crawl n'a lieu.

Le pont répond depuis un middleware global, poussé par le provider seulement quand l'option est active, plutôt que depuis une route : il passe avant le routage, hors du groupe `web` (sans session), et ne dépend pas de `route:cache`. Après avoir changé la variable avec une configuration en cache, relancez `php artisan config:cache`.

## Widget de tableau de bord (Filament, facultatif)

Depuis la version 0.7.0, une application qui utilise Filament 4 ou 5 peut afficher les chiffres du site dans sa propre administration : visiteurs par jour cumulés, pages vues et sessions sur les 30 derniers jours avec leur évolution sur la période précédente, une tendance des visiteurs, les cinq pages les plus vues, et un lien « Ouvrir dans Quiet Metrics ». Filament est seulement suggéré, jamais exigé : sans lui, le pont fonctionne comme avant.

1. Dans Quiet Metrics, **Espace membre → Jetons API**, créez un jeton restreint à ce site. Il ne fait que lire les statistiques et reste distinct des clés de collecte : le révoquer n'arrête jamais la mesure.
2. Ajoutez-le au `.env` :

   ```dotenv
   QUIET_METRICS_READ_TOKEN=12|xxxxxxxxxxxxxxxx
   ```

3. Déclarez le widget dans le provider de votre panel :

   ```php
   use QuietMetrics\Laravel\Filament\QuietMetricsWidget;

   ->widgets([QuietMetricsWidget::class])
   ```

Les chiffres sont lus côté serveur sur l'API de lecture (`summary`, `timeseries`, `breakdown` par page), sur l'hôte de `QUIET_METRICS_ENDPOINT`, avec des délais courts, et gardés dix minutes dans le cache de l'application sous une clé hachée : le jeton n'atteint jamais le navigateur ni une clé de cache en clair. Une valeur précédente `null` ou nulle, ou une fenêtre de comparaison que l'API marque comme non mesurée, se lit « Pas de comparaison », jamais une baisse. Un jeton refusé ou un service injoignable affichent un message neutre, jamais le corps de la réponse. Le widget suit le chargement différé de Filament, et tout utilisateur du panel qui voit le tableau de bord le voit : étendez la classe et redéfinissez `canView()` pour le restreindre, ou `$period` (un preset de l'API comme `7d`) pour changer la période. Traductions : anglais et français.

## Comment ça marche

Le provider construit un singleton `Client` (package cœur) à partir de la config `quiet-metrics`. Le middleware envoie la pageview dans `terminate()`, après l'envoi de la réponse au visiteur : aucun impact sur la latence perçue. Le contexte (URL, referrer, IP, User-Agent, langue) vient de l'objet `Request` et non des superglobales : correct sous Octane et workers persistants, dans les tests, et aligné sur les trusted proxies de l'application hôte. Côté cœur, seul ce que la plateforme lit est transmis (l'adresse de la page réduite à son origine, à son chemin et aux paramètres `utm_source`, `utm_medium`, `utm_campaign` et `ref` ; le référent réduit à son origine), l'envoi est non bloquant (socket fire-and-forget, repli cURL court) et tout échec est silencieux : l'analytics ne casse jamais le site.

## Tests

```bash
composer update && composer test
```

Suite Orchestra Testbench contre le serveur de capture HTTP du package cœur : pageview middleware signée (HMAC vérifiée), exclusions (JSON, PDF, POST, redirections), facade `event`, singleton configuré.

## Licence

MIT. Un produit [QuietSystems](https://quietsystems.dev) pour [Quiet Metrics](https://quietmetrics.dev).
