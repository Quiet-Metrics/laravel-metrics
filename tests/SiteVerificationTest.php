<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\Attributes\WithEnv;
use Orchestra\Testbench\TestCase;
use QuietMetrics\Laravel\Facades\QuietMetrics;
use QuietMetrics\Laravel\Middleware\ServeSiteVerification;
use QuietMetrics\Laravel\QuietMetricsServiceProvider;

/**
 * Preuve de propriete du site pour le crawl SEO, servie par le pont Laravel.
 *
 * Eteinte par defaut : le chemin doit alors appartenir entierement a
 * l'application (son 404, ou sa propre route). Le jeton attendu est recalcule
 * ici a la main, contrat avec la plateforme oblige.
 */
final class SiteVerificationTest extends TestCase
{
    private const PATH = '/.well-known/quietmetrics.json';

    private const SECRET = 'qm_sec_test';

    protected function getPackageProviders($app): array
    {
        return [QuietMetricsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('quiet-metrics.public_key', 'qm_pub_test');
        // Testbench applique les attributs #[DefineEnvironment] AVANT cette
        // methode : la cle n'est posee que si l'attribut n'en a pas decide.
        $app['config']->set('quiet-metrics.secret_key', $app['config']->get('quiet-metrics.secret_key') ?? self::SECRET);
        $app['config']->set('quiet-metrics.endpoint', 'http://127.0.0.1:9/api/v1/collect');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('qm', 16)));
    }

    protected function enableSeoCrawl($app): void
    {
        $app['config']->set('quiet-metrics.seo_crawl', true);
    }

    protected function enableSeoCrawlWithoutSecret($app): void
    {
        $app['config']->set('quiet-metrics.seo_crawl', true);
        // Chaine vide et non null : une variable d'environnement posee sans
        // valeur donne ce cas-la, et null serait ecrase par defineEnvironment().
        $app['config']->set('quiet-metrics.secret_key', '');
    }

    private function expectedDocument(): string
    {
        return '{"site_verification":["'.hash_hmac('sha256', 'quietmetrics-site-verification:v1', self::SECRET).'"]}';
    }

    private function middlewareIsRegistered(): bool
    {
        return $this->app->make(Kernel::class)->hasMiddleware(ServeSiteVerification::class);
    }

    public function test_the_option_is_off_by_default(): void
    {
        $this->assertFalse(config('quiet-metrics.seo_crawl'));
        $this->assertFalse($this->middlewareIsRegistered());

        $this->get(self::PATH)->assertNotFound();
    }

    public function test_when_off_the_application_keeps_its_own_route(): void
    {
        Route::get(self::PATH, fn () => response('application'));

        $this->get(self::PATH)->assertOk()->assertSeeText('application');
    }

    #[DefineEnvironment('enableSeoCrawl')]
    public function test_when_on_with_a_secret_the_document_is_served(): void
    {
        $this->assertTrue($this->middlewareIsRegistered());

        $response = $this->get(self::PATH);

        $response->assertOk();
        $this->assertSame($this->expectedDocument(), $response->getContent());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // Hors du groupe `web` : ni session, ni cookie d'aucune sorte.
        $this->assertSame([], $response->headers->getCookies());
    }

    #[DefineEnvironment('enableSeoCrawl')]
    public function test_the_query_string_does_not_matter(): void
    {
        $this->assertSame($this->expectedDocument(), $this->get(self::PATH.'?cache=bust')->getContent());
    }

    #[DefineEnvironment('enableSeoCrawl')]
    public function test_head_is_answered_without_a_body(): void
    {
        $response = $this->call('HEAD', self::PATH);

        $response->assertOk();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }

    #[DefineEnvironment('enableSeoCrawl')]
    public function test_the_document_wins_over_an_application_route(): void
    {
        Route::get(self::PATH, fn () => response('application'));

        $this->assertSame($this->expectedDocument(), $this->get(self::PATH)->getContent());
    }

    #[DefineEnvironment('enableSeoCrawl')]
    public function test_other_methods_and_paths_are_left_to_the_application(): void
    {
        Route::post(self::PATH, fn () => response('posted'));
        Route::get('/.well-known/security.txt', fn () => response('security'));

        $this->post(self::PATH)->assertOk()->assertSeeText('posted');
        $this->get('/.well-known/security.txt')->assertOk()->assertSeeText('security');
        $this->get(self::PATH.'x')->assertNotFound();
    }

    #[DefineEnvironment('enableSeoCrawl')]
    public function test_serving_the_document_measures_nothing(): void
    {
        QuietMetrics::fake();

        $this->get(self::PATH)->assertOk();

        QuietMetrics::assertNothingSent();
    }

    #[DefineEnvironment('enableSeoCrawlWithoutSecret')]
    public function test_when_on_without_a_secret_nothing_is_served(): void
    {
        $this->assertFalse($this->middlewareIsRegistered());

        $this->get(self::PATH)->assertNotFound();
    }

    #[WithEnv('QUIET_METRICS_SEO_CRAWL', 'true')]
    public function test_the_environment_variable_turns_it_on(): void
    {
        $this->assertTrue(config('quiet-metrics.seo_crawl'));

        $this->assertSame($this->expectedDocument(), $this->get(self::PATH)->getContent());
    }

    #[WithEnv('QUIET_METRICS_SEO_CRAWL', 'false')]
    public function test_the_environment_variable_can_say_false(): void
    {
        $this->assertFalse(config('quiet-metrics.seo_crawl'));

        $this->get(self::PATH)->assertNotFound();
    }
}
