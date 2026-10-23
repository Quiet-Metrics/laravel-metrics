@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Number;

    $locale = app()->getLocale();
    $date = fn (string $day): string => Carbon::parse($day)->locale($locale)->isoFormat('LL');
    $number = fn (?int $value): string => $value === null ? '' : Number::format($value, locale: $locale);
@endphp

<x-filament-widgets::widget class="qm-widget">
    <x-filament::section
        :heading="__('quiet-metrics::widget.heading')"
        :description="($stats['status'] ?? null) === 'ok' ? __('quiet-metrics::widget.period', ['from' => $date($stats['from']), 'to' => $date($stats['to'])]) : null"
    >
        @if (($stats['status'] ?? null) !== 'ok')
            {{-- Message neutre : jamais le jeton, jamais le corps d'une réponse. --}}
            <p role="status">{{ __('quiet-metrics::widget.'.($stats['status'] ?? 'unavailable')) }}</p>
        @else
            <x-slot name="afterHeader">
                <x-filament::link :href="$stats['url']">
                    {{ __('quiet-metrics::widget.open') }}
                </x-filament::link>
            </x-slot>

            <dl style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); margin: 0;">
                @foreach (['visitors', 'pageviews', 'sessions'] as $metric)
                    @php($kpi = $stats['kpis'][$metric])
                    <div>
                        <dt style="font-size: 0.875rem;">{{ __('quiet-metrics::widget.'.$metric) }}</dt>
                        <dd style="margin: 0.25rem 0 0; font-size: 1.5rem; font-weight: 600;">{{ $number($kpi['value']) }}</dd>
                        <dd style="margin: 0.25rem 0 0; font-size: 0.875rem;">
                            @if ($kpi['change'] === null)
                                {{ __('quiet-metrics::widget.no_comparison') }}
                            @elseif ($kpi['change'] === 0)
                                {{ __('quiet-metrics::widget.flat') }}
                            @else
                                {{ __('quiet-metrics::widget.'.($kpi['change'] > 0 ? 'up' : 'down'), ['value' => $number(abs($kpi['change']))]) }}
                            @endif
                        </dd>
                        @if ($metric === 'visitors')
                            <dd style="margin: 0.25rem 0 0; font-size: 0.75rem;">{{ __('quiet-metrics::widget.visitors_help') }}</dd>
                        @endif
                    </div>
                @endforeach
            </dl>

            @if ($sparkline !== '')
                <svg
                    role="img"
                    aria-label="{{ __('quiet-metrics::widget.trend', ['from' => $date($stats['from']), 'to' => $date($stats['to']), 'min' => $number(min($stats['trend'])), 'max' => $number(max($stats['trend']))]) }}"
                    viewBox="0 0 100 24"
                    preserveAspectRatio="none"
                    style="display: block; width: 100%; height: 3rem; margin-top: 1.5rem;"
                >
                    <polyline points="{{ $sparkline }}" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                </svg>
            @endif

            <table style="width: 100%; margin-top: 1.5rem; border-collapse: collapse; font-size: 0.875rem;">
                <caption style="text-align: start; font-weight: 600; padding-bottom: 0.5rem;">{{ __('quiet-metrics::widget.top_pages') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" style="text-align: start;">{{ __('quiet-metrics::widget.page') }}</th>
                        <th scope="col" style="text-align: end;">{{ __('quiet-metrics::widget.views') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stats['pages'] as $page)
                        <tr>
                            <td style="padding-top: 0.25rem; overflow-wrap: anywhere;">{{ $page['label'] === '~autres' ? __('quiet-metrics::widget.other_pages') : $page['label'] }}</td>
                            <td style="padding-top: 0.25rem; text-align: end;">{{ $number($page['value']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" style="padding-top: 0.25rem;">{{ __('quiet-metrics::widget.no_pages') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            @if ($stats['capped'])
                <p style="margin-top: 0.5rem; font-size: 0.75rem;">{{ __('quiet-metrics::widget.capped') }}</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
