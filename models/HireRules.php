<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

/**
 * Whether a hire period is one the business can take: whole days, first and last day on open days, enough notice, not
 * too far ahead; and whether an item is free for it. Pure: it is given the settings and "today", so it can be tested alone.
 */
class HireRules
{
    /**
     * @param array{openDays: array<int, bool>, minNoticeDays: int, maxDaysAhead: int} $config openDays by weekday, 1 = Monday to 7 = Sunday
     * @return array{startsOn: string, endsOn: string, days: int} both as Y-m-d
     * @throws \InvalidArgumentException with a message fit to show the customer
     */
    public static function period(array $config, string $startsOn, string $endsOn, \DateTimeImmutable $today): array
    {
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn, $today->getTimezone());
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn, $today->getTimezone());
        $bad = $first === false || $last === false || $first->format('Y-m-d') !== $startsOn || $last->format('Y-m-d') !== $endsOn;
        if ($bad) throw new \InvalidArgumentException('Please choose the first and last day');

        $backwards = $last < $first;
        if ($backwards) throw new \InvalidArgumentException('The last day cannot be before the first day');

        $today = $today->setTime(0, 0);
        $tooSoon = $first < $today->modify('+' . $config['minNoticeDays'] . ' days');
        if ($tooSoon) throw new \InvalidArgumentException('Please allow at least ' . self::daysText($config['minNoticeDays']) . ' notice');

        $tooFar = $first > $today->modify('+' . $config['maxDaysAhead'] . ' days');
        if ($tooFar) throw new \InvalidArgumentException('Hire can be requested up to ' . $config['maxDaysAhead'] . ' days ahead');

        foreach ([['first', $first, 'collection'], ['last', $last, 'return']] as [$which, $day, $what]) {
            $closed = empty($config['openDays'][(int) $day->format('N')]);
            if ($closed) throw new \InvalidArgumentException('We are closed on ' . $day->format('l') . 's, so the ' . $which . ' day (' . $what . ') must be another day');
        }

        return ['startsOn' => $first->format('Y-m-d'), 'endsOn' => $last->format('Y-m-d'), 'days' => (int) $first->diff($last)->days + 1];
    }

    /**
     * Whether the item is out of service on any day of the period (a repair, a service): the item's unavailable list as stored JSON.
     * The turnaround days after the hire are held too, so they count as part of the period.
     *
     * @param array<string, mixed>|null $item
     */
    public static function unavailableReason(?array $item, string $startsOn, string $heldUntil): ?string
    {
        if ($item === null) return null;

        $name = (string) ($item['name'] ?? 'That item');
        foreach (self::decode($item['unavailable'] ?? null) as $range) {
            $from = (string) ($range['from'] ?? '');
            $to = (string) (($range['to'] ?? '') ?: $from);
            $overlaps = $from !== '' && $from <= $heldUntil && $to >= $startsOn;
            if (!$overlaps) continue;

            $label = ($range['reason'] ?? '') !== '' ? ' (' . $range['reason'] . ')' : '';

            return $name . ' is unavailable from ' . (new \DateTimeImmutable($from))->format('D j M') . ' to ' . (new \DateTimeImmutable($to))->format('D j M') . $label;
        }

        return null;
    }

    /** The last day an item is held for a hire: its last day plus the group's turnaround days */
    public static function heldUntil(string $endsOn, int $turnaroundDays): string
    {
        return (new \DateTimeImmutable($endsOn))->modify("+{$turnaroundDays} days")->format('Y-m-d');
    }

    /** @return array<int|string, mixed> the stored JSON as an array, empty when there is none or it is not valid */
    public static function decode(mixed $stored): array
    {
        if (\is_array($stored)) return $stored;
        $empty = !\is_string($stored) || $stored === '';
        if ($empty) return [];

        $decoded = \json_decode($stored, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** Two periods overlap when each starts on or before the other's last day */
    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA <= $endB && $startB <= $endA;
    }

    private static function daysText(int $days): string
    {
        return $days === 1 ? '1 day' : $days . ' days';
    }
}
