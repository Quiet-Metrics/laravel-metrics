<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Filament stays optional: an application without it must install and boot
 * the bridge. Filament is installed for the test suite, so the guarantee is
 * held statically: the dependency is suggested, never required, and only the
 * widget class names it.
 */
final class OptionalFilamentTest extends TestCase
{
    public function test_filament_is_suggested_not_required(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

        $this->assertArrayNotHasKey('filament/filament', $composer['require']);
        $this->assertArrayHasKey('filament/filament', $composer['suggest']);
    }

    public function test_only_the_widget_depends_on_filament(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
            if ($file->isFile() && ! str_contains($file->getPathname(), '/Filament/')
                && str_contains((string) file_get_contents($file->getPathname()), 'Filament\\')) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
