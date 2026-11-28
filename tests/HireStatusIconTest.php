<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumHire;

use Osmium\Services\OsmiumHire\Models\HireStatusIcon;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/HireStatusIcon.php';

/**
 * The navbar icon is amber, says how many requests wait, and opens the list.
 */
final class HireStatusIconTest extends TestCase
{
    public function test_the_icon_is_amber_and_opens_the_list(): void
    {
        $icon = HireStatusIcon::icon(waiting: 3);

        $this->assertSame('warning', $icon['color']);
        $this->assertSame('hire/', $icon['path']);
        $this->assertSame('bx bx-key', $icon['icon']);
        $this->assertSame('3 hire requests waiting', $icon['title']);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $icon['id']);
    }

    public function test_one_request_is_singular(): void
    {
        $this->assertSame('1 hire request waiting', HireStatusIcon::icon(waiting: 1)['title']);
    }
}
