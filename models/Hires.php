<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * What a theme calls to build its hire form. The form posts to /api/hire (no trailing slash) with the fields group_id,
 * start and end (YYYY-MM-DD, the first and last day), name, email, phone, notes, token and the zip honeypot, and is
 * sent back to the page it came from.
 *
 *     $hire = new \Osmium\Services\OsmiumHire\Models\Hires($this->osmium->dataSource);
 *     $groups = $hire->groups();   // id, name, description, price
 *     $rules = $hire->rules($this->osmium->config->company->openingHours ?? null);   // openDays by weekday (1 = Monday), minNoticeDays, maxDaysAhead
 *     $notice = $hire->pullNotice();   // status (sent or failed) and message, shown once
 */
class Hires
{
    private const NOTICE_KEY = 'hire_notice';

    public function __construct(private OsmiumPDO $database)
    {
    }

    /**
     * Only groups that are on and have at least one item that is on
     *
     * @return list<array{id: int, name: string, description: ?string, price: ?string}>
     */
    public function groups(): array
    {
        $withItems = [];
        foreach ((new HireItem($this->database))->listAll() as $row) {
            if ($row['enabled']) $withItems[(int) $row['group_id']] = true;
        }

        $groups = [];
        foreach ((new HireGroup($this->database))->listAll() as $row) {
            if (!$row['enabled'] || empty($withItems[(int) $row['id']])) continue;
            $groups[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => $row['description'] ?? null,
                'price' => $row['price_text'] ?? null,
            ];
        }

        return $groups;
    }

    /**
     * @param mixed $companyHours pass $this->osmium->config->company->openingHours so the open days are the ones in Company > Location
     * @return array{openDays: array<int, bool>, minNoticeDays: int, maxDaysAhead: int}
     */
    public function rules(mixed $companyHours = null): array
    {
        $config = HireConfig::get(companyHours: $companyHours);
        unset($config['notifyEmails']); // The team's addresses are not for the page

        return $config;
    }

    /**
     * The message left for this visitor by their last hire request, once
     *
     * @return array{status: string, message: string}|null
     */
    public function pullNotice(?array &$session = null): ?array
    {
        $session ??= $_SESSION;
        $notice = $session[self::NOTICE_KEY] ?? null;
        unset($session[self::NOTICE_KEY]);
        $this->clearSessionNotice();

        return \is_array($notice) ? $notice : null;
    }

    public static function rememberNotice(array &$session, string $status, string $message): void
    {
        $session[self::NOTICE_KEY] = ['status' => $status, 'message' => $message];
    }

    private function clearSessionNotice(): void
    {
        unset($_SESSION[self::NOTICE_KEY]);
    }
}
