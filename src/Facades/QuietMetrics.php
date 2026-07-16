<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use QuietMetrics\Client;

/**
 * @method static void pageview(array $overrides = [])
 * @method static void event(string $name, array $props = [], array $overrides = [])
 *
 * @see \QuietMetrics\Client
 */
final class QuietMetrics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
