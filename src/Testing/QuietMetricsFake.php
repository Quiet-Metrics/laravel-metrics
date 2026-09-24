<?php

declare(strict_types=1);

namespace QuietMetrics\Laravel\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use QuietMetrics\Tracker;

/**
 * Remplaçant de test, posé par QuietMetrics::fake() à la place du client.
 *
 * Il enregistre les appels TELS QUE REÇUS, avant la minimisation que fait le
 * client à l'envoi, comme Mail::fake() enregistre des mailables et non des
 * e-mails rendus : un test vérifie ce que l'application demande, pas ce que
 * le transport en fait. Rien ne part sur le réseau.
 */
final class QuietMetricsFake implements Tracker
{
    /** @var list<array<string, mixed>> */
    private array $pageviews = [];

    /** @var list<array{name: string, props: array<string, mixed>, overrides: array<string, mixed>}> */
    private array $events = [];

    public function pageview(array $overrides = []): void
    {
        $this->pageviews[] = $overrides;
    }

    public function event(string $name, array $props = [], array $overrides = []): void
    {
        $this->events[] = ['name' => $name, 'props' => $props, 'overrides' => $overrides];
    }

    /** @return list<array<string, mixed>> le contexte de chaque page vue */
    public function pageviews(): array
    {
        return $this->pageviews;
    }

    /** @return list<array{name: string, props: array<string, mixed>, overrides: array<string, mixed>}> */
    public function events(?string $name = null): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $event): bool => $name === null || $event['name'] === $name,
        ));
    }

    /** @param (Closure(array<string, mixed>): bool)|null $callback reçoit le contexte de la page vue */
    public function assertPageviewSent(?Closure $callback = null): void
    {
        $matching = array_filter(
            $this->pageviews,
            static fn (array $context): bool => $callback === null || $callback($context),
        );

        PHPUnit::assertNotEmpty($matching, 'No matching pageview was sent to Quiet Metrics.');
    }

    /**
     * @param array<string, mixed>|(Closure(array<string, mixed>, array<string, mixed>): bool)|null $props
     *        les propriétés attendues, ou une fonction qui reçoit (props, overrides)
     */
    public function assertEventSent(string $name, array|Closure|null $props = null): void
    {
        $matching = array_filter($this->events($name), static function (array $event) use ($props): bool {
            if ($props === null) {
                return true;
            }

            return $props instanceof Closure
                ? (bool) $props($event['props'], $event['overrides'])
                : $event['props'] == $props;
        });

        PHPUnit::assertNotEmpty($matching, sprintf('The expected event "%s" was not sent to Quiet Metrics.', $name));
    }

    public function assertEventNotSent(string $name): void
    {
        PHPUnit::assertEmpty($this->events($name), sprintf('The unexpected event "%s" was sent to Quiet Metrics.', $name));
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertSame([], $this->pageviews, 'Pageviews were sent to Quiet Metrics.');
        PHPUnit::assertSame([], $this->events, 'Events were sent to Quiet Metrics.');
    }
}
