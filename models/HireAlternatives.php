<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

/**
 * What a good counter assistant would say when the days asked for are taken: other days the same length that would
 * work for that group. All text is written for the customer, who never hears about turnaround days or which item.
 */
class HireAlternatives
{
    private const SEARCH_DAYS = 60;
    private const MAX_OFFERS = 2;

    /**
     * @param array<string, mixed> $config the rules from HireConfig::get() (with openDays)
     */
    public function __construct(private Hire $hires, private array $config, private \DateTimeImmutable $today)
    {
    }

    /**
     * Whether any of the items is free for the whole period: working (not out of service) and nothing confirmed over those days
     *
     * @param list<array<string, mixed>> $items
     */
    public function anyFree(array $items, string $startsOn, string $endsOn, int $turnaroundDays): bool
    {
        return $this->hires->findFreeItem($items, $startsOn, $endsOn, $turnaroundDays) !== null;
    }

    /**
     * The refusal to show the customer, with the nearest first days for a hire of the same length
     *
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $items the group's enabled items
     */
    public function message(array $group, array $items, string $startsOn, string $endsOn): string
    {
        $length = (int) (new \DateTimeImmutable($startsOn))->diff(new \DateTimeImmutable($endsOn))->days;
        $turnaround = (int) $group['turnaround_days'];

        $offers = $this->nearest(items: $items, startsOn: $startsOn, length: $length, turnaroundDays: $turnaround);
        $opening = 'Sorry, ' . $group['name'] . ' is not available for those days.';
        if ($offers === []) return $opening . ' Please try other dates.';

        $text = [];
        foreach ($offers as $offer) {
            $first = new \DateTimeImmutable($offer);
            $last = $first->modify("+{$length} days");
            $text[] = $first->format('D j M') . ($length > 0 ? ' to ' . $last->format('D j M') : '');
        }

        return $opening . ' We could do ' . \implode(' or ', $text) . '.';
    }

    /**
     * The first days closest to the one asked for that suit: the nearest one earlier and the nearest later where possible
     *
     * @param list<array<string, mixed>> $items
     * @return list<string> Y-m-d, in date order
     */
    private function nearest(array $items, string $startsOn, int $length, int $turnaroundDays): array
    {
        $wanted = new \DateTimeImmutable($startsOn);
        $before = null;
        $after = null;
        for ($offset = 1; $offset <= self::SEARCH_DAYS && ($before === null || $after === null); $offset++) {
            if ($before === null) $before = $this->suits($wanted->modify("-{$offset} days"), $length, $items, $turnaroundDays) ? $wanted->modify("-{$offset} days")->format('Y-m-d') : null;
            if ($after === null) $after = $this->suits($wanted->modify("+{$offset} days"), $length, $items, $turnaroundDays) ? $wanted->modify("+{$offset} days")->format('Y-m-d') : null;
        }

        return \array_slice(\array_values(\array_filter([$before, $after])), 0, self::MAX_OFFERS);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function suits(\DateTimeImmutable $first, int $length, array $items, int $turnaroundDays): bool
    {
        $last = $first->modify("+{$length} days");

        try {
            HireRules::period(config: $this->config, startsOn: $first->format('Y-m-d'), endsOn: $last->format('Y-m-d'), today: $this->today);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        return $this->anyFree($items, $first->format('Y-m-d'), $last->format('Y-m-d'), $turnaroundDays);
    }
}
