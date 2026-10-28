<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumHire;

use Osmium\Services\OsmiumHire\Models\Hire;
use Osmium\Services\OsmiumHire\Models\HireAlternatives;
use Osmium\Services\OsmiumHire\Models\HireGroup;
use Osmium\Services\OsmiumHire\Models\HireItem;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/HireRules.php';
require_once __DIR__ . '/../models/HireGroup.php';
require_once __DIR__ . '/../models/HireItem.php';
require_once __DIR__ . '/../models/Hire.php';
require_once __DIR__ . '/../models/HireAlternatives.php';

/**
 * The hire tables against the installed schema (rolled back with each test; the service must be installed).
 */
class HireDbTest extends DatabaseTestCase
{
    private function group(int $turnaround = 1): int
    {
        return (new HireGroup($this->db))->create(['name' => 'zzDigger', 'description' => null, 'priceText' => null, 'turnaroundDays' => $turnaround, 'enabled' => true, 'sortOrder' => 0]);
    }

    private function item(int $groupId, string $name, ?string $unavailable = null): array
    {
        $id = (new HireItem($this->db))->create(groupId: $groupId, name: $name, reference: null, enabled: true, sortOrder: 0, unavailable: $unavailable);

        return (new HireItem($this->db))->get($id);
    }

    private function book(Hire $hires, int $groupId, int $itemId, string $from, string $to, string $status = 'confirmed', string $email = 'pat@example.com'): int
    {
        $created = $hires->create(['groupId' => $groupId, 'itemId' => $itemId, 'name' => 'Pat', 'email' => $email, 'phone' => null, 'notes' => null], $from, $to);
        $hires->setStatus(id: $created['id'], status: $status, sequence: 0);

        return $created['id'];
    }

    public function test_references_are_unique_and_requests_start_as_requested(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group();
        $item = $this->item($groupId, 'zzA');

        $first = $hires->create(['groupId' => $groupId, 'itemId' => $item['id'], 'name' => 'Pat', 'email' => 'a@example.com', 'phone' => null, 'notes' => null], '2026-11-05', '2026-11-06');
        $second = $hires->create(['groupId' => $groupId, 'itemId' => $item['id'], 'name' => 'Sam', 'email' => 'b@example.com', 'phone' => null, 'notes' => null], '2026-11-12', '2026-11-13');

        $this->assertNotSame($first['reference'], $second['reference']);
        $this->assertSame('requested', $hires->get($first['id'])['status']);
    }

    public function test_turnaround_days_hold_the_item_after_the_last_day(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group(turnaround: 2);
        $item = $this->item($groupId, 'zzA');
        $this->book($hires, $groupId, $item['id'], '2026-11-03', '2026-11-05');

        $this->assertNotNull($hires->findOverlap(excludeId: 0, itemId: $item['id'], startsOn: '2026-11-07', endsOn: '2026-11-08', turnaroundDays: 2));
        $this->assertNull($hires->findOverlap(excludeId: 0, itemId: $item['id'], startsOn: '2026-11-08', endsOn: '2026-11-09', turnaroundDays: 2));
    }

    public function test_a_new_hire_ending_just_before_a_booking_needs_its_own_turnaround_to_fit(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group(turnaround: 2);
        $item = $this->item($groupId, 'zzA');
        $this->book($hires, $groupId, $item['id'], '2026-11-10', '2026-11-12');

        $this->assertNotNull($hires->findOverlap(excludeId: 0, itemId: $item['id'], startsOn: '2026-11-06', endsOn: '2026-11-09', turnaroundDays: 2));
        $this->assertNull($hires->findOverlap(excludeId: 0, itemId: $item['id'], startsOn: '2026-11-04', endsOn: '2026-11-07', turnaroundDays: 2));
    }

    public function test_only_confirmed_hires_block_unless_asked_to_include_requests(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group();
        $item = $this->item($groupId, 'zzA');
        $this->book($hires, $groupId, $item['id'], '2026-11-03', '2026-11-05', 'requested');

        $this->assertNull($hires->findOverlap(excludeId: 0, itemId: $item['id'], startsOn: '2026-11-04', endsOn: '2026-11-04'));
        $this->assertNotNull($hires->findOverlap(excludeId: 0, itemId: $item['id'], startsOn: '2026-11-04', endsOn: '2026-11-04', includeRequested: true));
    }

    public function test_the_first_free_item_is_chosen_and_out_of_service_items_are_skipped(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group();
        $repair = $this->item($groupId, 'zzA', \json_encode([['from' => '2026-11-01', 'to' => '2026-11-30', 'reason' => 'Repair']]));
        $busy = $this->item($groupId, 'zzB');
        $free = $this->item($groupId, 'zzC');
        $this->book($hires, $groupId, $busy['id'], '2026-11-03', '2026-11-05');

        $chosen = $hires->findFreeItem([$repair, $busy, $free], '2026-11-04', '2026-11-04', 1);

        $this->assertSame($free['id'], $chosen['id']);
        $this->assertNull($hires->findFreeItem([$repair, $busy], '2026-11-04', '2026-11-04', 1));
    }

    public function test_a_waiting_request_makes_an_item_the_last_choice(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group();
        $a = $this->item($groupId, 'zzA');
        $b = $this->item($groupId, 'zzB');
        $this->book($hires, $groupId, $a['id'], '2026-11-04', '2026-11-04', 'requested');

        $this->assertSame($b['id'], $hires->findFreeItem([$a, $b], '2026-11-04', '2026-11-04', 1)['id']);
    }

    public function test_the_same_customer_asking_twice_for_the_same_start_is_a_duplicate(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group();
        $item = $this->item($groupId, 'zzA');
        $this->book($hires, $groupId, $item['id'], '2026-11-04', '2026-11-05', 'requested', 'dup@example.com');

        $this->assertTrue($hires->isDuplicate(email: 'dup@example.com', startsOn: '2026-11-04', groupId: $groupId));
        $this->assertFalse($hires->isDuplicate(email: 'other@example.com', startsOn: '2026-11-04', groupId: $groupId));
    }

    public function test_the_refusal_offers_the_nearest_dates_that_work(): void
    {
        $hires = new Hire($this->db);
        $groupId = $this->group(turnaround: 0);
        $item = $this->item($groupId, 'zzA');
        $this->book($hires, $groupId, $item['id'], '2026-11-10', '2026-11-12');
        $config = ['openDays' => [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 7 => true], 'minNoticeDays' => 0, 'maxDaysAhead' => 365];

        $message = (new HireAlternatives($hires, $config, new \DateTimeImmutable('2026-11-02')))->message(
            group: (new HireGroup($this->db))->get($groupId),
            items: [$item],
            startsOn: '2026-11-11',
            endsOn: '2026-11-11',
        );

        $this->assertStringContainsString('is not available for those days', $message);
        $this->assertStringContainsString('Mon 9 Nov or Fri 13 Nov', $message);
    }
}
