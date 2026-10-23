<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Filament;

use Filament\Widgets\Widget;
use QuietMetrics\Laravel\Stats\DashboardStats;

/**
 * Widget Filament facultatif : visiteurs, pages vues, sessions, tendance et
 * pages les plus vues du site, lus côté serveur sur l'API de lecture.
 *
 * Seule classe du paquet qui dépend de Filament : rien ne la charge tant que
 * l'application ne l'enregistre pas dans un panel, si bien que le paquet
 * fonctionne sans Filament installé.
 *
 * Étendre la classe pour changer la période (`$period`, un preset de l'API)
 * ou restreindre l'affichage (`canView()`).
 */
class QuietMetricsWidget extends Widget
{
    protected string $view = 'quiet-metrics::filament.widget';

    protected int|string|array $columnSpan = 'full';

    protected string $period = '30d';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $stats = app(DashboardStats::class)->read($this->period);

        return [
            'stats' => $stats,
            'sparkline' => self::sparkline($stats['trend'] ?? []),
        ];
    }

    /**
     * Points d'une polyligne SVG de 100 x 24, rendue côté serveur : pas de
     * JavaScript ni de bibliothèque de graphiques pour une courbe de tendance.
     *
     * @param  list<int>  $values
     */
    public static function sparkline(array $values): string
    {
        $count = count($values);
        if ($count < 2) {
            return '';
        }

        $max = max(max($values), 1);
        $points = [];
        foreach (array_values($values) as $i => $value) {
            $points[] = round($i * 100 / ($count - 1), 2).','.round(23 - $value * 22 / $max, 2);
        }

        return implode(' ', $points);
    }
}
