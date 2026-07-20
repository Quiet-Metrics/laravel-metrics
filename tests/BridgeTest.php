<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use QuietMetrics\Client;
use QuietMetrics\Laravel\Facades\QuietMetrics;
use QuietMetrics\Laravel\QuietMetricsServiceProvider;
use QuietMetrics\Tests\CaptureServer;
use Orchestra\Testbench\TestCase;

/**
 * Le pont testé comme un vrai projet Laravel (Testbench) : provider, config,
 * middleware `quiet-metrics` en terminate(), facade, le tout contre un serveur
 * de capture HTTP réel (celui du package cœur, via le symlink du path repo).
 */
final class BridgeTest extends TestCase
{
    private static CaptureServer $server;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/../vendor/quiet-metrics/php-metrics/tests/CaptureServer.php';
        self::$server = new CaptureServer();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$server->reset();
    }

    protected function getPackageProviders($app): array
    {
        return [QuietMetricsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('quiet-metrics.public_key', 'qm_pub_test');
        $app['config']->set('quiet-metrics.secret_key', 'qm_sec_test');
        $app['config']->set('quiet-metrics.endpoint', self::$server->endpoint());
        $app['config']->set('quiet-metrics.trust_proxy_headers', false);
    }

    public function test_le_middleware_envoie_une_pageview_signee_apres_la_reponse(): void
    {
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->get('/tarifs', ['User-Agent' => 'NavigateurTest/1.0'])->assertOk();

        $requests = self::$server->requests();
        $this->assertCount(1, $requests);

        $payload = json_decode($requests[0]['body'], true);
        $this->assertSame('qm_pub_test', $payload['k']);
        $this->assertSame('pageview', $payload['t']);
        $this->assertStringEndsWith('/tarifs', $payload['u']);
        $this->assertSame('NavigateurTest/1.0', $payload['ua']);

        // Signature HMAC valide : le serveur de collecte honorera ip/ua/ts.
        $timestamp = $requests[0]['headers']['x-qm-timestamp'];
        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$requests[0]['body'], 'qm_sec_test'),
            $requests[0]['headers']['x-qm-signature'],
        );
    }

    public function test_le_middleware_ignore_json_erreurs_et_non_get(): void
    {
        Route::middleware('quiet-metrics')->get('/api-like', fn () => response('ok'));
        Route::middleware('quiet-metrics')->post('/form', fn () => response('ok'));
        Route::middleware('quiet-metrics')->get('/introuvable', fn () => response('non', 404));

        $this->getJson('/api-like')->assertOk();          // attend du JSON → ignoré
        $this->post('/form')->assertOk();                 // non-GET → ignoré
        $this->get('/introuvable')->assertNotFound();     // réponse non 2xx → ignorée

        $this->assertSame([], self::$server->requests(1, 400));
    }

    public function test_la_facade_envoie_un_evenement(): void
    {
        QuietMetrics::event('inscription', ['plan' => 'pro'], ['url' => 'https://monsite.fr/register']);

        $payload = json_decode(self::$server->requests()[0]['body'], true);
        $this->assertSame('event', $payload['t']);
        $this->assertSame('inscription', $payload['n']);
        $this->assertSame(['plan' => 'pro'], $payload['p']);
    }

    public function test_le_client_est_un_singleton_configure(): void
    {
        $this->assertSame($this->app->make(Client::class), $this->app->make(Client::class));
    }

    public function test_la_config_est_publiee_sous_le_nom_que_le_provider_relit(): void
    {
        $chemins = ServiceProvider::pathsToPublish(
            QuietMetricsServiceProvider::class,
            'quiet-metrics-config',
        );

        $this->assertNotEmpty($chemins, 'le tag de publication quiet-metrics-config a disparu');

        foreach ($chemins as $destination) {
            $this->assertSame(
                'quiet-metrics.php',
                basename($destination),
                'la config publiee doit porter le nom de la cle relue par register() (quiet-metrics), sinon l edition de l utilisateur reste sans effet',
            );
        }
    }
}
