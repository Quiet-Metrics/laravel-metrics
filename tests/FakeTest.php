<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use QuietMetrics\Client;
use QuietMetrics\Laravel\Facades\QuietMetrics;
use QuietMetrics\Laravel\QuietMetricsServiceProvider;
use QuietMetrics\Laravel\Testing\QuietMetricsFake;
use QuietMetrics\Tracker;

/**
 * Le faux officiel : ce qu'un intégrateur appelle dans ses propres tests.
 * Aucun serveur de capture ici, justement : le faux ne doit rien envoyer.
 */
final class FakeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [QuietMetricsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('quiet-metrics.public_key', 'qm_pub_test');
        $app['config']->set('quiet-metrics.secret_key', 'qm_sec_test');
        // Un envoi réel échouerait en silence ici : le faux doit l'intercepter AVANT.
        $app['config']->set('quiet-metrics.endpoint', 'http://127.0.0.1:9/collect');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('qm', 16)));
    }

    public function test_le_tracker_resolu_est_le_client_configure(): void
    {
        $this->assertSame($this->app->make(Client::class), $this->app->make(Tracker::class));
    }

    public function test_fake_remplace_le_tracker_du_conteneur_et_de_la_facade(): void
    {
        $fake = QuietMetrics::fake();

        $this->assertInstanceOf(QuietMetricsFake::class, $fake);
        $this->assertSame($fake, $this->app->make(Tracker::class));
    }

    public function test_un_evenement_de_l_application_est_enregistre(): void
    {
        QuietMetrics::fake();

        QuietMetrics::event('inscription', ['plan' => 'pro']);

        QuietMetrics::assertEventSent('inscription');
        QuietMetrics::assertEventSent('inscription', ['plan' => 'pro']);
        QuietMetrics::assertEventSent('inscription', fn (array $props): bool => $props['plan'] === 'pro');
        QuietMetrics::assertEventNotSent('achat');
    }

    public function test_la_page_vue_du_middleware_passe_par_le_faux(): void
    {
        $fake = QuietMetrics::fake();
        Route::middleware('quiet-metrics')->get('/tarifs', fn () => response('ok'));

        $this->get('/tarifs?utm_source=nl')->assertOk();

        $fake->assertPageviewSent(fn (array $context): bool => str_ends_with($context['url'], '/tarifs?utm_source=nl'));
        $this->assertCount(1, $fake->pageviews());
    }

    public function test_assert_nothing_sent(): void
    {
        $fake = QuietMetrics::fake();
        $fake->assertNothingSent();

        $fake->event('inscription');

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingSent();
    }

    public function test_une_assertion_fausse_echoue(): void
    {
        $fake = QuietMetrics::fake();
        $fake->event('inscription', ['plan' => 'free']);

        $this->expectException(AssertionFailedError::class);
        $fake->assertEventSent('inscription', ['plan' => 'pro']);
    }

    public function test_un_evenement_absent_fait_echouer_assert_event_not_sent_a_l_envers(): void
    {
        $fake = QuietMetrics::fake();
        $fake->event('achat');

        $this->expectException(AssertionFailedError::class);
        $fake->assertEventNotSent('achat');
    }

    public function test_events_filtre_par_nom(): void
    {
        $fake = QuietMetrics::fake();
        $fake->event('a', ['x' => 1]);
        $fake->event('b');
        $fake->event('a', ['x' => 2]);

        $this->assertSame([['x' => 1], ['x' => 2]], array_column($fake->events('a'), 'props'));
        $this->assertCount(3, $fake->events());
    }
}
