<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

/**
 * What the hire service adds to the dashboard's quick links tile row (dashboard.quick_links): the label says how many requests are waiting.
 */
class HireDashboardLinks
{
    /**
     * @param array{osmium: object} $payload
     * @return array<string, string> the Manage Hire tile
     */
    public static function quickLink(array $payload): array
    {
        $model = new Hire($payload['osmium']->dataSource);
        $waiting = $model->countRequested();

        return self::tile(waiting: $waiting);
    }

    /** @return array<string, string> */
    public static function tile(int $waiting): array
    {
        return [
            'label' => 'Hire' . ($waiting > 0 ? " · {$waiting} waiting" : ''),
            'title' => 'Manage Hire',
            'icon' => 'bx-key',
            'button' => 'View Hire',
            'url' => 'hire/',
            'attention' => $waiting > 0 ? '1' : '',
        ];
    }
}
