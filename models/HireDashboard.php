<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

/**
 * The dashboard card: how many requests are waiting for an answer, and the first few.
 */
class HireDashboard
{
    /**
     * @param array{osmium: object, role?: string} $payload
     * @return array<string, mixed>
     */
    public static function widget(array $payload): array
    {
        $model = new Hire($payload['osmium']->dataSource);
        $waiting = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => $row['status'] === 'requested'));

        $lines = [];
        foreach (\array_slice($waiting, 0, 5) as $row) {
            $lines[] = [
                'text' => (string) $row['customer_name'] . ', ' . (string) $row['group_name'],
                'sub' => self::period($row),
                'route' => 'hire',
            ];
        }

        return [
            'id' => 'osmium-hire',
            'title' => 'Hire requests',
            'icon' => 'bx-calendar-check',
            'roles' => ['admin'],
            'order' => 20,
            'count' => \count($waiting),
            'link' => ['label' => 'Open hire', 'route' => 'hire'],
            'lines' => $lines,
            'emptyText' => 'No requests waiting.',
        ];
    }

    private static function period(array $row): string
    {
        $first = new \DateTimeImmutable($row['starts_on']);
        $last = new \DateTimeImmutable($row['ends_on']);

        return $first->format('D j M') . ($row['starts_on'] === $row['ends_on'] ? '' : ' to ' . $last->format('D j M'));
    }
}
