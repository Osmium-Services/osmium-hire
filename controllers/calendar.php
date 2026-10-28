<?php

use Osmium\Services\OsmiumHire\Models\Hire;
use Osmium\Services\OsmiumHire\Models\HireIcs;
use Osmium\Services\OsmiumHire\Models\HireMail;

/**
 * Calendar file for a hire (GET /api/hire-calendar?ref=REFERENCE). The reference is the key: ten characters
 * chosen at random, only in the customer's emails. The file holds the event, the days and the reference, never the
 * customer's details. A requested or declined hire is not an event, so it answers 404.
 */

$reference = \preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['ref'] ?? ''));
$hire = $reference !== '' ? (new Hire($this->osmium->dataSource))->getByReference($reference) : null;

$isEvent = $hire !== null && \in_array($hire['status'], ['confirmed', 'cancelled'], true);
if (!$isEvent) {
    \http_response_code(404);
    echo 'Not found';
    exit;
}

$siteName = (string) $this->osmium->config->site->name;
$host = (string) \parse_url($this->osmium->config->site->FQDN, PHP_URL_HOST);
$stamp = new \DateTimeImmutable('now');

$file = HireIcs::build(
    hire: $hire,
    summary: HireMail::eventTitle($hire, $siteName),
    description: 'Hire reference ' . $hire['reference'] . ($hire['item_name'] ? '. Item: ' . $hire['item_name'] : ''),
    host: $host,
    stamp: $stamp,
);

\header('Content-Type: text/calendar; charset=utf-8');
\header('Content-Disposition: attachment; filename="hire-' . $hire['reference'] . '.ics"');
\header('X-Robots-Tag: noindex');
echo $file;
exit;
