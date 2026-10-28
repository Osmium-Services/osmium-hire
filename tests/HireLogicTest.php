<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumHire;

use Osmium\Services\OsmiumHire\Models\HireIcs;
use Osmium\Services\OsmiumHire\Models\HireMail;
use Osmium\Services\OsmiumHire\Models\HireRequest;
use Osmium\Services\OsmiumHire\Models\HireRules;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/HireIcs.php';
require_once __DIR__ . '/../models/HireMail.php';
require_once __DIR__ . '/../models/HireRequest.php';
require_once __DIR__ . '/../models/HireRules.php';

/**
 * The rules, request cleaning, calendar file and email words: everything that needs no database.
 */
class HireLogicTest extends TestCase
{
    // A Monday
    private const TODAY = '2026-11-02';

    /** Open Monday to Friday, a day's notice, 180 days ahead */
    private function config(): array
    {
        return ['openDays' => [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => false, 7 => false], 'minNoticeDays' => 1, 'maxDaysAhead' => 180];
    }

    private function period(string $from, string $to): array
    {
        return HireRules::period(config: $this->config(), startsOn: $from, endsOn: $to, today: new \DateTimeImmutable(self::TODAY));
    }

    private function hire(array $over = []): array
    {
        return $over + ['reference' => 'ABC123', 'customer_name' => 'Pat', 'group_name' => '3 tonne digger', 'item_name' => 'Digger 2', 'item_reference' => 'SN-998', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-09', 'ics_sequence' => 0, 'status' => 'confirmed'];
    }

    public function test_a_weekday_period_is_accepted_and_counts_its_days(): void
    {
        $this->assertSame(['startsOn' => '2026-11-04', 'endsOn' => '2026-11-06', 'days' => 3], $this->period('2026-11-04', '2026-11-06'));
    }

    public function test_closed_days_in_the_middle_are_fine_but_not_at_either_end(): void
    {
        $this->assertSame(7, $this->period('2026-11-04', '2026-11-10')['days']);

        $this->expectExceptionMessage('closed on Saturdays');
        $this->period('2026-11-04', '2026-11-07');
    }

    public function test_a_closed_first_day_is_refused(): void
    {
        $this->expectExceptionMessage('closed on Sundays');
        $this->period('2026-11-08', '2026-11-10');
    }

    public function test_backwards_notice_and_distance_are_refused(): void
    {
        foreach ([['2026-11-06', '2026-11-04', 'cannot be before'], ['2026-11-02', '2026-11-03', 'at least 1 day'], ['2027-09-01', '2027-09-02', 'up to 180 days'], ['nope', '2026-11-04', 'first and last day']] as [$from, $to, $words]) {
            try {
                $this->period($from, $to);
                $this->fail("{$from} to {$to} should be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($words, $e->getMessage());
            }
        }
    }

    public function test_the_item_is_held_through_turnaround_days(): void
    {
        $this->assertSame('2026-11-11', HireRules::heldUntil('2026-11-09', 2));
        $this->assertSame('2026-11-09', HireRules::heldUntil('2026-11-09', 0));
    }

    public function test_out_of_service_dates_block_the_held_period(): void
    {
        $item = ['unavailable' => \json_encode([['from' => '2026-11-10', 'to' => '2026-11-12', 'reason' => 'Hydraulic repair']])];

        $this->assertNull(HireRules::unavailableReason($item, '2026-11-03', '2026-11-09'));
        $this->assertStringContainsString('Hydraulic repair', (string) HireRules::unavailableReason($item, '2026-11-05', '2026-11-10')); // the turnaround reaches into the repair
        $this->assertNull(HireRules::unavailableReason(null, '2026-11-05', '2026-11-10'));
    }

    public function test_overlap_is_inclusive_of_both_ends(): void
    {
        $this->assertTrue(HireRules::overlaps('2026-11-05', '2026-11-09', '2026-11-09', '2026-11-12'));
        $this->assertFalse(HireRules::overlaps('2026-11-05', '2026-11-09', '2026-11-10', '2026-11-12'));
    }

    public function test_a_request_is_cleaned(): void
    {
        $request = HireRequest::parse(['group_id' => '3', 'start' => '2026-11-05', 'end' => '2026-11-09', 'name' => ' Pat ', 'email' => 'pat@example.com', 'phone' => '', 'notes' => '']);

        $this->assertSame(3, $request['groupId']);
        $this->assertSame('Pat', $request['name']);
        $this->assertNull($request['phone']);
        $this->assertSame('2026-11-05', $request['start']);
    }

    public function test_a_request_without_a_group_or_with_a_bad_email_is_refused(): void
    {
        foreach ([['group_id' => '0', 'email' => 'pat@example.com', 'message' => 'what you would like to hire'], ['group_id' => '3', 'email' => 'nope', 'message' => 'valid email']] as $case) {
            try {
                HireRequest::parse(['group_id' => $case['group_id'], 'start' => '2026-11-05', 'end' => '2026-11-09', 'name' => 'Pat', 'email' => $case['email']]);
                $this->fail('Should be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($case['message'], $e->getMessage());
            }
        }
    }

    public function test_the_calendar_event_is_all_day_ending_the_day_after_the_last_day(): void
    {
        $file = HireIcs::build(hire: $this->hire(), summary: 'Hire of digger', description: 'x', host: 'example.com', stamp: new \DateTimeImmutable('2026-11-02 08:00:00'));

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261105', $file);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261110', $file);
        $this->assertStringNotContainsString('Pat', $file);
    }

    public function test_the_email_shows_the_days_and_pickup_details_but_not_turnaround_or_reference(): void
    {
        $mail = HireMail::confirmed($this->hire(), 'Acme Hire', 'https://x/ics', 'https://x/g', "Collect after 8am\nBring photo ID");

        $this->assertStringContainsString('Thursday 5 November to Monday 9 November 2026 (5 days)', $mail['html']);
        $this->assertStringContainsString('Collect after 8am', $mail['html']);
        $this->assertStringNotContainsString('SN-998', $mail['html']);
        $this->assertStringNotContainsString('urnaround', $mail['html']);
    }

    public function test_the_email_without_pickup_details_has_no_empty_section(): void
    {
        $mail = HireMail::confirmed($this->hire(), 'Acme Hire', 'https://x/ics', 'https://x/g');

        $this->assertStringNotContainsString('Collection and return', $mail['html']);
    }

    public function test_decline_and_cancel_carry_the_message_and_phone(): void
    {
        foreach ([HireMail::declined($this->hire(), 'Acme Hire', 'Free from the 16th', '01234 567890'), HireMail::cancelled($this->hire(), 'Acme Hire', 'Free from the 16th', '01234 567890')] as $mail) {
            $this->assertStringContainsString('Free from the 16th', $mail['html']);
            $this->assertStringContainsString('01234 567890', $mail['html']);
        }
    }

    public function test_a_one_day_hire_reads_as_one_day(): void
    {
        $this->assertSame('Thursday 5 November 2026', HireMail::when($this->hire(['ends_on' => '2026-11-05'])));
    }
}
