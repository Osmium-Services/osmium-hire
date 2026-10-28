<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

/**
 * The words of every email a hire sends. Free of the database and the mailer, so they can be tested on their own.
 * Each method returns the subject and the HTML body.
 */
class HireMail
{
    /**
     * @param array<string, mixed> $hire A hire row with group_name and item_name
     * @return array{subject: string, html: string}
     */
    public static function received(array $hire, string $siteName): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'We have your hire request',
            heading: 'Thanks, ' . $hire['customer_name'],
            paragraphs: [
                'We have received your request and will confirm it shortly. Nothing is booked until you hear from us.',
                self::details($hire),
            ],
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function confirmed(array $hire, string $siteName, string $icsUrl, string $googleUrl, string $pickupDetails = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your hire is confirmed',
            heading: 'Your hire is booked, ' . $hire['customer_name'],
            paragraphs: \array_values(\array_filter([
                self::details($hire),
                $pickupDetails !== '' ? '<p style="white-space:pre-line"><strong>Collection and return</strong><br>' . self::e($pickupDetails) . '</p>' : '',
                '<p>Add it to your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>.</p>',
                'If you need to change or cancel, just reply to this email.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function changed(array $hire, string $siteName, string $icsUrl, string $googleUrl, string $pickupDetails = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your hire has changed',
            heading: 'Your hire has been updated',
            paragraphs: \array_values(\array_filter([
                self::details($hire),
                $pickupDetails !== '' ? '<p style="white-space:pre-line"><strong>Collection and return</strong><br>' . self::e($pickupDetails) . '</p>' : '',
                '<p>Please update your calendar: <a href="' . self::e($googleUrl) . '">Google Calendar</a> or <a href="' . self::e($icsUrl) . '">any other calendar (.ics file)</a>. Remove the earlier entry if your calendar keeps both.</p>',
                'If this does not suit you, just reply to this email.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function declined(array $hire, string $siteName, string $message = '', string $phone = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'About your hire request',
            heading: 'Sorry, ' . $hire['customer_name'],
            paragraphs: \array_values(\array_filter([
                'We are not able to take this hire:',
                self::details($hire),
                $message !== '' ? '<p style="white-space:pre-line">' . self::e($message) . '</p>' : '',
                $phone !== '' ? 'To arrange other dates, please call us on ' . $phone . ' or send a new request on our website.' : 'To arrange other dates, please send a new request on our website.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * @return array{subject: string, html: string}
     */
    public static function cancelled(array $hire, string $siteName, string $message = '', string $phone = ''): array
    {
        return self::compose(
            siteName: $siteName,
            subject: 'Your hire is cancelled',
            heading: 'Your hire is cancelled',
            paragraphs: \array_values(\array_filter([
                self::details($hire),
                $message !== '' ? '<p style="white-space:pre-line">' . self::e($message) . '</p>' : '',
                $phone !== '' ? 'To arrange other dates, please call us on ' . $phone . ' or send a new request on our website.' : 'To arrange other dates, please send a new request on our website.',
            ], static fn(string $paragraph): bool => $paragraph !== '')),
        );
    }

    /**
     * The note to the business when a request arrives
     *
     * @return array{subject: string, html: string}
     */
    public static function requestForTeam(array $hire, string $siteName, string $adminUrl): array
    {
        $contact = self::e((string) $hire['customer_name']) . ', ' . self::e((string) $hire['customer_email'])
            . ($hire['customer_phone'] ? ', ' . self::e((string) $hire['customer_phone']) : '');
        $notes = !empty($hire['customer_notes']) ? '<p><strong>Their message:</strong><br>' . \nl2br(self::e((string) $hire['customer_notes'])) . '</p>' : '';

        return self::compose(
            siteName: $siteName,
            subject: 'New hire request from ' . $hire['customer_name'],
            heading: 'New hire request',
            paragraphs: [
                self::details($hire),
                '<p><strong>From:</strong> ' . $contact . '</p>' . $notes,
                '<p><a href="' . self::e($adminUrl) . '">Confirm or decline it in the admin</a></p>',
            ],
        );
    }

    /** The one-line summary used as a calendar event's title */
    public static function eventTitle(array $hire, string $siteName): string
    {
        return 'Hire of ' . $hire['group_name'] . ' from ' . $siteName;
    }

    /** "Friday 6 November to Monday 9 November 2026 (4 days)", or just the one day */
    public static function when(array $hire): string
    {
        $first = new \DateTimeImmutable($hire['starts_on']);
        $last = new \DateTimeImmutable($hire['ends_on']);
        $days = (int) $first->diff($last)->days + 1;
        if ($days === 1) return $first->format('l j F Y');

        return $first->format('l j F') . ' to ' . $last->format('l j F Y') . ' (' . $days . ' days)';
    }

    private static function details(array $hire): string
    {
        $rows = [
            'What' => (string) $hire['group_name'],
            'When' => self::when($hire),
            'Reference' => (string) $hire['reference'],
        ];
        $hasItem = !empty($hire['item_name']);
        if ($hasItem) $rows = ['What' => $rows['What'], 'Item' => (string) $hire['item_name']] + $rows;

        $html = '<table cellpadding="4" style="border-collapse:collapse">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td><strong>' . $label . '</strong></td><td>' . self::e($value) . '</td></tr>';
        }

        return $html . '</table>';
    }

    /**
     * @param list<string> $paragraphs
     * @return array{subject: string, html: string}
     */
    private static function compose(string $siteName, string $subject, string $heading, array $paragraphs): array
    {
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $isMarkup = \str_starts_with($paragraph, '<');
            $body .= $isMarkup ? $paragraph : '<p>' . self::e($paragraph) . '</p>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;max-width:560px">'
            . '<h2 style="margin:0 0 12px">' . self::e($heading) . '</h2>' . $body
            . '<p style="color:#777;margin-top:24px">' . self::e($siteName) . '</p></div>';

        return ['subject' => $subject, 'html' => $html];
    }

    private static function e(string $text): string
    {
        return \htmlspecialchars($text, ENT_QUOTES);
    }
}
