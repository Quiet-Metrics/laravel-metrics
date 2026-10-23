<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase;
use QuietMetrics\Laravel\Filament\QuietMetricsWidget;
use QuietMetrics\Laravel\QuietMetricsServiceProvider;
use QuietMetrics\Laravel\Stats\DashboardStats;

/**
 * The optional Filament widget, rendered through Livewire against a faked
 * read API. Filament is a dev dependency here only: the package suggests it.
 */
final class FilamentWidgetTest extends TestCase
{
    private const TOKEN = '42|readTokenThatMustNeverReachTheBrowser';

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            FilamentServiceProvider::class,
            WidgetsServiceProvider::class,
            QuietMetricsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('qm', 16)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('quiet-metrics.public_key', 'qm_pub_site');
        $app['config']->set('quiet-metrics.read_token', self::TOKEN);
        $app['config']->set('quiet-metrics.endpoint', 'https://stats.example.test/api/v1/collect');
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function fakeApi(array $summary = [], int $status = 200): void
    {
        Http::fake([
            '*/summary*' => Http::response(array_replace_recursive([
                'site' => 'example.test',
                'period' => ['preset' => '30d', 'from' => '2026-09-24', 'to' => '2026-10-23'],
                'filters' => [],
                'kpis' => ['pageviews' => 9873, 'visitors' => 4210, 'sessions' => 4630, 'events' => 0, 'bounce_rate' => 47.2, 'avg_duration' => 74],
                'previous' => ['pageviews' => 9145, 'visitors' => 3877, 'sessions' => 4630, 'events' => 0, 'bounce_rate' => 49.1, 'avg_duration' => 71],
                'comparison' => ['mode' => 'previous', 'from' => '2026-08-25', 'to' => '2026-09-23', 'measured' => true],
            ], $summary), $status),
            '*/timeseries*' => Http::response(['period' => [], 'granularity' => 'day', 'data' => [
                ['date' => '2026-10-22', 'visitors' => 120],
                ['date' => '2026-10-23', 'visitors' => 150],
            ]], $status),
            '*/breakdown*' => Http::response(['dimension' => 'page', 'period' => [], 'data' => [
                ['label' => '/pricing', 'value' => 1284],
                ['label' => '~autres', 'value' => 77],
            ], 'capped' => ['any' => true, 'dimensions' => []]], $status),
        ]);
    }

    private function render(): string
    {
        return Livewire::withoutLazyLoading()->test(QuietMetricsWidget::class)->html();
    }

    public function test_it_renders_the_figures_read_server_side(): void
    {
        $this->fakeApi();

        $html = $this->render();

        $this->assertStringContainsString('Daily visitors, summed', $html);
        $this->assertStringContainsString('4,210', $html);
        $this->assertStringContainsString('Up 9% on the previous period', $html);
        $this->assertStringContainsString('Unchanged from the previous period', $html);
        $this->assertStringContainsString('/pricing', $html);
        $this->assertStringContainsString('Other pages, grouped', $html);
        $this->assertStringContainsString('no visit is lost', $html);
        $this->assertStringContainsString('<polyline', $html);
        $this->assertStringContainsString('href="https://stats.example.test/app"', $html);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && str_starts_with($request->url(), 'https://stats.example.test/api/v1/stats/qm_pub_site/'));
    }

    public function test_the_token_never_reaches_the_rendered_widget(): void
    {
        $this->fakeApi();

        $this->assertStringNotContainsString(self::TOKEN, $this->render());
        $this->assertStringNotContainsString('readToken', $this->render());
    }

    public function test_a_null_previous_value_reads_as_no_comparison_not_minus_100(): void
    {
        $this->fakeApi(['previous' => ['visitors' => null, 'pageviews' => null, 'sessions' => null]]);

        $html = $this->render();

        $this->assertSame(3, substr_count($html, 'No comparison'));
        $this->assertStringNotContainsString('Down', $html);
    }

    public function test_an_unmeasured_comparison_window_is_not_a_comparison_base(): void
    {
        $this->fakeApi(['comparison' => ['measured' => false]]);

        $this->assertSame(3, substr_count($this->render(), 'No comparison'));
    }

    public function test_responses_are_cached_under_a_key_without_the_token(): void
    {
        $this->fakeApi();

        $this->render();
        $this->render();

        Http::assertSentCount(3);

        $storage = (fn () => $this->storage)->call(Cache::store('array')->getStore());
        $this->assertCount(1, $storage);
        $this->assertStringNotContainsString(self::TOKEN, (string) array_key_first($storage));
    }

    public function test_an_api_error_shows_a_neutral_message_and_is_cached_one_minute(): void
    {
        Http::fake(['*' => Http::response('SQLSTATE secret detail', 500)]);

        $html = $this->render();
        $this->render();

        $this->assertStringContainsString('The figures are unavailable right now.', $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
        Http::assertSentCount(1);

        $this->travel(DashboardStats::FAILURE_CACHE_SECONDS + 1)->seconds();
        $this->render();
        Http::assertSentCount(2);
    }

    public function test_an_invalid_or_expired_token_says_so_without_showing_it(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $html = $this->render();

        $this->assertStringContainsString('did not accept the read token', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
    }

    public function test_a_plan_or_scope_refusal_is_told_apart_from_an_invalid_token(): void
    {
        Http::fake(['*' => Http::response(['message' => "L'API de lecture est réservée aux plans Pro et Agence."], 403)]);

        $html = $this->render();

        $this->assertStringContainsString('requires the Pro or Agency plan', $html);
        $this->assertStringNotContainsString('réservée', $html);
    }

    public function test_a_malformed_success_is_unavailable_not_an_exception(): void
    {
        $this->fakeApi(['period' => ['from' => 'not a date']]);

        $this->assertStringContainsString('The figures are unavailable right now.', $this->render());
        $this->assertSame('unavailable', $this->app->make(DashboardStats::class)->read()['status']);
    }

    public function test_only_string_page_labels_are_kept(): void
    {
        Http::fake([
            '*/summary*' => Http::response(['period' => ['from' => '2026-09-24', 'to' => '2026-10-23'], 'kpis' => [], 'previous' => []]),
            '*/timeseries*' => Http::response(['data' => []]),
            '*/breakdown*' => Http::response(['data' => [['label' => ['x' => 1], 'value' => 3], ['label' => null, 'value' => 2], ['label' => '/ok', 'value' => 1]]]),
        ]);

        $pages = $this->app->make(DashboardStats::class)->read()['pages'];

        $this->assertSame([['label' => '/ok', 'value' => 1]], $pages);
        $this->assertStringContainsString('/ok', $this->render());
    }

    public function test_the_token_is_never_sent_over_plain_http_except_to_localhost(): void
    {
        $this->fakeApi();
        config()->set('quiet-metrics.endpoint', 'http://stats.example.test/api/v1/collect');

        $this->assertStringContainsString('must use https', $this->render());
        Http::assertNothingSent();

        config()->set('quiet-metrics.endpoint', 'http://127.0.0.1:8000/api/v1/collect');
        $this->assertStringContainsString('4,210', $this->render());
    }

    public function test_a_redirect_is_not_followed_with_the_token(): void
    {
        Http::fake([
            'stats.example.test/*' => Http::response('', 302, ['Location' => 'https://elsewhere.example/steal']),
            'elsewhere.example/*' => Http::response(['kpis' => []]),
        ]);

        $this->assertStringContainsString('The figures are unavailable right now.', $this->render());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'elsewhere.example'));
    }

    public function test_without_a_read_token_nothing_is_requested(): void
    {
        config()->set('quiet-metrics.read_token', null);
        Http::fake();

        $this->assertStringContainsString('QUIET_METRICS_READ_TOKEN', $this->render());
        Http::assertNothingSent();
    }

    public function test_the_widget_speaks_french(): void
    {
        app()->setLocale('fr');
        $this->fakeApi();

        $html = $this->render();

        $this->assertStringContainsString('Visiteurs par jour, cumulés', $html);
        $this->assertStringContainsString('En hausse de 9 %', $html);
    }

    public function test_change_is_null_when_there_is_nothing_to_compare(): void
    {
        $this->assertNull(DashboardStats::change(10, null));
        $this->assertNull(DashboardStats::change(10, 0));
        $this->assertSame(-50, DashboardStats::change(5, 10));
    }
}
