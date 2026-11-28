<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumHire;

use Osmium\Services\OsmiumHire\Models\HireDashboardLinks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/HireDashboardLinks.php';

/**
 * The dashboard tile opens the hire list and says how many requests are waiting.
 */
final class HireDashboardLinksTest extends TestCase
{
    public function test_the_tile_opens_the_list(): void
    {
        $tile = HireDashboardLinks::tile(waiting: 0);

        $this->assertSame('hire/', $tile['url']);
        $this->assertSame('Manage Hire', $tile['title']);
        $this->assertSame('Hire', $tile['label']);
        $this->assertSame(['label', 'title', 'icon', 'button', 'url', 'attention'], \array_keys($tile));
    }

    public function test_the_icon_asks_for_attention_only_when_requests_wait(): void
    {
        $this->assertSame('', HireDashboardLinks::tile(waiting: 0)['attention']);
        $this->assertSame('1', HireDashboardLinks::tile(waiting: 2)['attention']);
    }

    public function test_the_label_counts_waiting_requests(): void
    {
        $this->assertSame('Hire · 3 waiting', HireDashboardLinks::tile(waiting: 3)['label']);
    }
}
