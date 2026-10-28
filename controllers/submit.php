<?php

use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;
use Osmium\Services\OsmiumHire\Models\Hire;
use Osmium\Services\OsmiumHire\Models\HireAlternatives;
use Osmium\Services\OsmiumHire\Models\HireConfig;
use Osmium\Services\OsmiumHire\Models\HireNotifier;
use Osmium\Services\OsmiumHire\Models\HireRequest;
use Osmium\Services\OsmiumHire\Models\HireItem;
use Osmium\Services\OsmiumHire\Models\HireRules;
use Osmium\Services\OsmiumHire\Models\HireGroup;
use Osmium\Services\OsmiumHire\Models\Hires;

/**
 * Hire request endpoint (POST /api/hire). Fields: group_id, start and end (Y-m-d, the first and last day),
 * name, email, phone (optional), notes (optional), plus the form's token and the zip honeypot, and the answer of any
 * bot protection installed (form.verify). The visitor is always sent back to the page they came from, with a
 * one-time notice for the theme to show.
 */

$sg = $this->osmium->superGlobal;

$requestMethod = $sg->get(type: 'SERVER', name: 'REQUEST_METHOD');
$notPost = $requestMethod !== 'POST';
if ($notPost) {
    $this->osmium->header->redirect(targetURL: '/', statusCode: 302);
}

$referrer = $_SERVER['HTTP_REFERER'] ?? '/';
$back = \parse_url(url: $referrer, component: PHP_URL_PATH) ?: '/'; // Only the path: never send a visitor to another site
$notice = fn(string $status, string $message) => Hires::rememberNotice(session: $_SESSION, status: $status, message: $message);

$honeyPot = $sg->get(type: 'POST', name: 'zip');
$botDetected = !empty($honeyPot);

$submittedToken = $sg->get(type: 'POST', name: 'token');
$sessionToken = $sg->get(type: 'SESSION', name: 'token');
$tokenIsValid = !empty($submittedToken)
    && !empty($sessionToken)
    && \hash_equals(known_string: $sessionToken, user_string: $submittedToken);

$tokenIssuedAt = $sg->get(type: 'SESSION', name: 'token_issued_at');
$secondsSinceTokenIssued = $tokenIssuedAt ? (\time() - (int) $tokenIssuedAt) : 0;
$submittedTooFast = $secondsSinceTokenIssued < 4;

unset($_SESSION['token'], $_SESSION['token_issued_at']); // One token, one submission

// Any installed bot protection answers the form.verify hook; this service never names one
$protectionAnswers = (new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource))))
    ->collect('form.verify', ['form' => 'hire', 'post' => $_POST, 'dataSource' => $this->osmium->dataSource]);
$protectionFailed = \in_array(false, $protectionAnswers, true);

$looksLikeABot = $botDetected || !$tokenIsValid || $submittedTooFast || $protectionFailed;
if ($looksLikeABot) {
    $notice('failed', HireRequest::TECHNICAL_PROBLEM); // Never say which check failed
    $this->osmium->header->redirect(targetURL: $back, statusCode: 302);
}

try {
    $request = HireRequest::parse($_POST);

    $db = $this->osmium->dataSource;
    $group = (new HireGroup($db))->get($request['groupId']);
    $groupUnavailable = $group === null || !$group['enabled'];
    if ($groupUnavailable) throw new \InvalidArgumentException('Please choose what you would like to hire');

    $config = HireConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);
    $today = new \DateTimeImmutable('today');
    $period = HireRules::period(config: $config, startsOn: $request['start'], endsOn: $request['end'], today: $today);

    $hires = new Hire($db);
    $items = \array_values(\array_filter((new HireItem($db))->listAll(), static fn(array $row): bool => (bool) $row['enabled'] && (int) $row['group_id'] === (int) $group['id']));
    $turnaround = (int) $group['turnaround_days'];

    $item = $hires->findFreeItem($items, $period['startsOn'], $period['endsOn'], $turnaround);
    if ($item === null) {
        $alternatives = new HireAlternatives(hires: $hires, config: $config, today: $today);
        throw new \InvalidArgumentException($alternatives->message(group: $group, items: $items, startsOn: $period['startsOn'], endsOn: $period['endsOn']));
    }

    $isDuplicate = $hires->isDuplicate(email: $request['email'], startsOn: $period['startsOn'], groupId: $request['groupId']);
    if ($isDuplicate) throw new \InvalidArgumentException('You have already asked for those days. We will be in touch.');

    $request['itemId'] = (int) $item['id']; // Customers choose a group; the business can change the item when it confirms
    $created = $hires->create(request: $request, startsOn: $period['startsOn'], endsOn: $period['endsOn']);
    (new HireNotifier($this->osmium))->requested($hires->get($created['id']));

    $notice('sent', 'Thanks, your request has been sent. We will confirm it by email.');
} catch (\InvalidArgumentException $e) {
    $notice('failed', $e->getMessage()); // These messages are written for the customer
} catch (\Throwable $e) {
    \error_log('Hire: request failed: ' . $e->getMessage());
    $notice('failed', HireRequest::TECHNICAL_PROBLEM);
}

$this->osmium->header->redirect(targetURL: $back, statusCode: 302);
