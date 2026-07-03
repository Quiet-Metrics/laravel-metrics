<?php

declare(strict_types=1);

namespace LaBoiteACode\WebAnalytics\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use LaBoiteACode\WebAnalytics\Client;

/**
 * @method static void pageview(array $overrides = [])
 * @method static void event(string $name, array $props = [], array $overrides = [])
 *
 * @see \LaBoiteACode\WebAnalytics\Client
 */
final class WebAnalytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
