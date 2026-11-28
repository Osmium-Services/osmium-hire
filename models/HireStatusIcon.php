<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

/**
 * admin.status_icons: an amber icon in the admin navbar while hire requests are waiting for an answer, nothing otherwise.
 * One count query, no network.
 */
class HireStatusIcon
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public static function icons(array $payload): array
    {
        $waiting = (new Hire($payload['osmium']->dataSource))->countRequested();

        return $waiting > 0 ? [self::icon(waiting: $waiting)] : [];
    }

    /** @return array<string, mixed> */
    public static function icon(int $waiting): array
    {
        return [
            'id' => 'hire-waiting',
            'title' => $waiting . ' hire ' . ($waiting === 1 ? 'request' : 'requests') . ' waiting',
            'icon' => 'bx bx-key',
            'path' => 'hire/',
            'color' => 'warning',
            'crossed' => false,
        ];
    }
}
