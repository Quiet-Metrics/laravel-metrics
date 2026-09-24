<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use QuietMetrics\Laravel\Testing\QuietMetricsFake;
use QuietMetrics\Tracker;

/**
 * @method static void pageview(array $overrides = [])
 * @method static void event(string $name, array $props = [], array $overrides = [])
 * @method static array pageviews()
 * @method static array events(?string $name = null)
 * @method static void assertPageviewSent(?\Closure $callback = null)
 * @method static void assertEventSent(string $name, array|\Closure|null $props = null)
 * @method static void assertEventNotSent(string $name)
 * @method static void assertNothingSent()
 *
 * @see \QuietMetrics\Client
 * @see \QuietMetrics\Laravel\Testing\QuietMetricsFake
 */
final class QuietMetrics extends Facade
{
    /**
     * Remplace le client par un faux qui enregistre au lieu d'envoyer, dans la
     * façade ET dans le conteneur : les pages vues du middleware y passent
     * aussi, puisqu'il dépend de Tracker.
     */
    public static function fake(): QuietMetricsFake
    {
        $fake = new QuietMetricsFake();
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Tracker::class;
    }
}
