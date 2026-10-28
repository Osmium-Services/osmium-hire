<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

/**
 * The site-wide hire rules. Open days are not kept here: they come from Company > Location (company.openingHours).
 * This service's own settings file (app/config/services/osmium-hire.json.php) holds the minimum notice in days, how far
 * ahead a hire may start, and who is emailed when a request comes in.
 */
class HireConfig
{
    public const PATH = 'app/config/services/osmium-hire.json.php';
    public const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @return array{minNoticeDays: int, maxDaysAhead: int, notifyEmails: list<string>}
     */
    public static function defaults(): array
    {
        return ['minNoticeDays' => 1, 'maxDaysAhead' => 180, 'notifyEmails' => []];
    }

    /**
     * The rules with 'openDays' added: for each weekday (1 = Monday to 7 = Sunday) whether the company is open.
     *
     * @param mixed $companyHours config company.openingHours (array or decoded object)
     * @return array{openDays: array<int, bool>, minNoticeDays: int, maxDaysAhead: int, notifyEmails: list<string>}
     */
    public static function get(string $path = self::PATH, mixed $companyHours = null): array
    {
        $rules = self::stored($path);
        $rules['openDays'] = self::openDays($companyHours);

        return $rules;
    }

    /**
     * @return array{minNoticeDays: int, maxDaysAhead: int, notifyEmails: list<string>}
     */
    public static function stored(string $path = self::PATH): array
    {
        $defaults = self::defaults();

        $fileExists = \file_exists($path);
        if (!$fileExists) return $defaults;

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) return $defaults;

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true);
        $saved = \is_array($decoded) ? ($decoded['hire'] ?? []) : [];
        if (!\is_array($saved)) return $defaults;

        return [
            'minNoticeDays' => \max(0, \min(365, (int) ($saved['minNoticeDays'] ?? $defaults['minNoticeDays']))),
            'maxDaysAhead' => \max(1, \min(730, (int) ($saved['maxDaysAhead'] ?? $defaults['maxDaysAhead']))),
            'notifyEmails' => self::cleanEmails($saved['notifyEmails'] ?? []),
        ];
    }

    /**
     * @return array<int, bool> 1 = Monday to 7 = Sunday, true when the company opens that day
     */
    public static function openDays(mixed $companyHours): array
    {
        $company = \json_decode(\json_encode($companyHours) ?: '[]', associative: true);
        $company = \is_array($company) ? $company : [];

        $days = [];
        foreach (self::DAY_NAMES as $day => $name) {
            $row = $company[$name] ?? null;
            $days[$day] = \is_array($row) && empty($row['closed']) && self::isTime((string) ($row['opens'] ?? '')) && self::isTime((string) ($row['closes'] ?? '')) && $row['closes'] > $row['opens'];
        }

        return $days;
    }

    /**
     * Saves the rules; throws if any of them is not valid
     *
     * @throws \InvalidArgumentException
     */
    public static function save(array $settings, string $path = self::PATH): array
    {
        $clean = self::validate($settings);

        $dir = \dirname($path);
        $dirMissing = !\is_dir($dir);
        if ($dirMissing) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(value: ['hire' => $clean], flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        \file_put_contents($path, "<?php exit(); ?>\n" . $json . "\n");

        return $clean;
    }

    /**
     * @return array{minNoticeDays: int, maxDaysAhead: int, notifyEmails: list<string>}
     * @throws \InvalidArgumentException
     */
    public static function validate(array $settings): array
    {
        $notice = (int) ($settings['minNoticeDays'] ?? -1);
        $noticeBad = $notice < 0 || $notice > 365;
        if ($noticeBad) throw new \InvalidArgumentException('Minimum notice must be between 0 and 365 days');

        $ahead = (int) ($settings['maxDaysAhead'] ?? 0);
        $aheadBad = $ahead < 1 || $ahead > 730;
        if ($aheadBad) throw new \InvalidArgumentException('How far ahead must be between 1 and 730 days');

        $emails = self::cleanEmails($settings['notifyEmails'] ?? []);
        $rawCount = \count(self::splitEmails($settings['notifyEmails'] ?? []));
        if ($rawCount !== \count($emails)) throw new \InvalidArgumentException('One of the notification email addresses is not valid');

        return ['minNoticeDays' => $notice, 'maxDaysAhead' => $ahead, 'notifyEmails' => $emails];
    }

    public static function isTime(string $value): bool
    {
        return \preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** @return list<string> */
    public static function splitEmails(mixed $value): array
    {
        $parts = \is_array($value) ? $value : \preg_split('/[\s,;]+/', (string) $value);

        return \array_values(\array_filter(\array_map(static fn(mixed $part): string => \trim((string) $part), $parts ?: []), static fn(string $part): bool => $part !== ''));
    }

    /** @return list<string> */
    public static function cleanEmails(mixed $value): array
    {
        $valid = \array_filter(self::splitEmails($value), static fn(string $email): bool => \filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        return \array_values(\array_unique($valid));
    }
}
