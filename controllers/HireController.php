<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\OsmiumHire\Models\Hire;
use Osmium\Services\OsmiumHire\Models\HireAlternatives;
use Osmium\Services\OsmiumHire\Models\HireConfig;
use Osmium\Services\OsmiumHire\Models\HireGroup;
use Osmium\Services\OsmiumHire\Models\HireItem;
use Osmium\Services\OsmiumHire\Models\HireNotifier;
use Osmium\Services\OsmiumHire\Models\HireRules;

/**
 * Hire controller: the requests, what can be hired (groups and the items in them), and the rules.
 *
 * Routes:
 *   - index()    → /admin/hire/
 *   - settings() → /admin/settings/hire/
 *   - action()   → /admin/hire/action/   (AJAX)
 */
class HireController extends AdminController
{
    private const RECORD_TYPE = 'hire';

    // =========================================================================
    // Public Actions
    // =========================================================================

    public function index(): void
    {
        $db = $this->osmium->dataSource;
        $hires = new Hire($db);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/hire.php';
        $this->data['admin']['hires'] = \array_map($this->formatHire(...), $hires->listAll());
        $this->data['admin']['deletedHires'] = \array_map($this->formatDeleted(...), $hires->listDeleted());
        $this->data['admin']['groups'] = \array_map($this->formatGroup(...), (new HireGroup($db))->listAll());
        $this->data['admin']['items'] = \array_map($this->formatItem(...), (new HireItem($db))->listAll());
        $this->loadRules();

        $this->setView('hire/index.phtml');
    }

    /**
     * The setup page (Services > Osmium Hire): groups, items and the rules
     */
    public function settings(): void
    {
        $db = $this->osmium->dataSource;

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/hire.php';
        $this->data['admin']['groups'] = \array_map($this->formatGroup(...), (new HireGroup($db))->listAll());
        $this->data['admin']['items'] = \array_map($this->formatItem(...), (new HireItem($db))->listAll());
        $this->loadRules();

        $this->setView('hire/settings.phtml');
    }

    private function loadRules(): void
    {
        $companyHours = $this->osmium->config->company->openingHours ?? null;
        $this->data['admin']['settings'] = HireConfig::get(companyHours: $companyHours);
        $this->data['admin']['settings']['notifyEmailsText'] = \implode("\n", $this->data['admin']['settings']['notifyEmails']);
        $this->data['admin']['dayNames'] = HireConfig::DAY_NAMES;
    }

    private function rules(): array
    {
        return HireConfig::get(companyHours: $this->osmium->config->company->openingHours ?? null);
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $action = $input['action'] ?? '';
        $id = (int) ($input['id'] ?? 0);

        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $needsId = \in_array($action, ['confirm', 'decline', 'cancel', 'reschedule', 'delete', 'restore', 'permanently_delete', 'delete_group', 'delete_item'], true);
        $missingParams = !$action || ($needsId && !$id);
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        $message = \trim((string) ($input['message'] ?? ''));

        try {
            $result = match ($action) {
                'confirm' => $this->confirm(id: $id, pickupDetails: $message),
                'decline' => $this->decline(id: $id, message: $message),
                'cancel' => $this->cancel(id: $id, message: $message),
                'create' => $this->createByStaff($input),
                'reschedule' => $this->reschedule(id: $id, input: $input),
                'delete' => $this->deleteHire($id),
                'restore' => $this->restoreHire($id),
                'permanently_delete' => $this->permanentlyDelete($id),
                'purge_deleted' => $this->purgeDeleted(),
                'save_group' => $this->saveGroup($input),
                'delete_group' => $this->deleteGroup($id),
                'save_item' => $this->saveItem($input),
                'delete_item' => $this->deleteItem($id),
                'save_settings' => $this->saveSettings($input),
                default => throw new \InvalidArgumentException('Unknown action'),
            };

            $this->admin->jsonSuccess($result);
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Hires
    // =========================================================================

    /** @param string $pickupDetails what the customer is told about collection and return, kept with the hire */
    private function confirm(int $id, string $pickupDetails): array
    {
        $model = new Hire($this->osmium->dataSource);
        $hire = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'confirmed');
        $this->refuseIfClash(model: $model, hire: $hire, itemId: $hire['item_id'] !== null ? (int) $hire['item_id'] : null, startsOn: $hire['starts_on'], endsOn: $hire['ends_on'], turnaroundDays: (int) ($hire['turnaround_days'] ?? 0));

        $model->setStatus(id: $id, status: 'confirmed', sequence: (int) $hire['ics_sequence'], pickupDetails: \mb_substr($pickupDetails, 0, 2000));
        $this->logChange(action: 'Confirmed', id: $id, name: $hire['customer_name']);
        (new HireNotifier($this->osmium))->confirmed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function decline(int $id, string $message): array
    {
        $model = new Hire($this->osmium->dataSource);
        $hire = $this->findOpen(model: $model, id: $id, allowed: ['requested'], verb: 'declined');

        $model->setStatus(id: $id, status: 'declined', sequence: (int) $hire['ics_sequence']);
        $this->logChange(action: 'Declined', id: $id, name: $hire['customer_name']);
        (new HireNotifier($this->osmium))->declined(hire: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    private function cancel(int $id, string $message): array
    {
        $model = new Hire($this->osmium->dataSource);
        $hire = $this->findOpen(model: $model, id: $id, allowed: ['requested', 'confirmed'], verb: 'cancelled');

        $model->setStatus(id: $id, status: 'cancelled', sequence: (int) $hire['ics_sequence'] + 1);
        $this->logChange(action: 'Cancelled', id: $id, name: $hire['customer_name']);
        (new HireNotifier($this->osmium))->cancelled(hire: $model->get($id), message: \mb_substr($message, 0, 1000));

        return ['success' => true, 'id' => $id];
    }

    /**
     * A hire made for a customer who rang or called in: confirmed at once (and emailed, when there is an address).
     * Open days, out-of-service dates and clashes still apply; the notice and how-far-ahead limits are for the website.
     */
    private function createByStaff(array $input): array
    {
        $db = $this->osmium->dataSource;

        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Enter the customer\'s name (up to 100 characters)');

        $email = \trim((string) ($input['email'] ?? ''));
        $phone = \trim((string) ($input['phone'] ?? ''));
        $emailBad = $email !== '' && (\filter_var($email, FILTER_VALIDATE_EMAIL) === false || \strlen($email) > 150);
        if ($emailBad) throw new \InvalidArgumentException('That email address is not valid');

        $noContact = $email === '' && $phone === '';
        if ($noContact) throw new \InvalidArgumentException('Enter a phone number or an email address');
        if (\strlen($phone) > 30) throw new \InvalidArgumentException('Phone number is limited to 30 characters');

        $group = (new HireGroup($db))->get((int) ($input['group_id'] ?? 0));
        if ($group === null) throw new \InvalidArgumentException('Choose what is being hired');

        $itemId = (int) ($input['item_id'] ?? 0);
        $chosen = $itemId > 0 ? (new HireItem($db))->get($itemId) : null;
        $itemWrong = $itemId > 0 && ($chosen === null || (int) $chosen['group_id'] !== (int) $group['id']);
        if ($itemWrong) throw new \InvalidArgumentException('Choose an item from that group');

        $config = $this->rules();
        $today = new \DateTimeImmutable('today');
        $period = HireRules::period(config: ['minNoticeDays' => -100000, 'maxDaysAhead' => 100000] + $config, startsOn: \trim((string) ($input['start'] ?? '')), endsOn: \trim((string) ($input['end'] ?? '')), today: $today);

        $model = new Hire($db);
        $turnaround = (int) $group['turnaround_days'];
        $items = $chosen !== null ? [$chosen] : \array_values(\array_filter((new HireItem($db))->listAll(), static fn(array $row): bool => (bool) $row['enabled'] && (int) $row['group_id'] === (int) $group['id']));
        $assigned = $model->findFreeItem($items, $period['startsOn'], $period['endsOn'], $turnaround);
        if ($assigned === null) {
            $alternatives = new HireAlternatives(hires: $model, config: $config, today: $today);
            throw new \InvalidArgumentException($alternatives->message(group: $group, items: $items, startsOn: $period['startsOn'], endsOn: $period['endsOn']));
        }

        $notes = \trim((string) ($input['notes'] ?? ''));
        $created = $model->create(
            request: ['groupId' => (int) $group['id'], 'itemId' => (int) $assigned['id'], 'name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null, 'notes' => $notes !== '' ? $notes : null],
            startsOn: $period['startsOn'],
            endsOn: $period['endsOn'],
        );
        $model->setStatus(id: $created['id'], status: 'confirmed', sequence: 0, pickupDetails: $this->nullIfEmpty((string) ($input['pickup_details'] ?? '')));
        $this->logChange(action: 'Created by staff', id: $created['id'], name: $name);
        (new HireNotifier($this->osmium))->confirmed($model->get($created['id']));

        return ['success' => true, 'id' => $created['id']];
    }

    private function reschedule(int $id, array $input): array
    {
        $db = $this->osmium->dataSource;
        $model = new Hire($db);
        $hire = $model->get($id);
        $missing = $hire === null || $hire['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Hire not found');

        $group = (new HireGroup($db))->get((int) ($input['group_id'] ?? 0));
        if ($group === null) throw new \InvalidArgumentException('Choose what is being hired');

        $itemId = (int) ($input['item_id'] ?? 0);
        $item = $itemId > 0 ? (new HireItem($db))->get($itemId) : null;
        $itemWrong = $itemId > 0 && ($item === null || (int) $item['group_id'] !== (int) $group['id']);
        if ($itemWrong) throw new \InvalidArgumentException('Choose an item from that group');

        $startsOn = \trim((string) ($input['start'] ?? ''));
        $endsOn = \trim((string) ($input['end'] ?? ''));
        $itemValue = $itemId > 0 ? $itemId : null;
        $turnaround = (int) $group['turnaround_days'];

        $changed = $startsOn !== $hire['starts_on'] || $endsOn !== $hire['ends_on'] || $itemValue !== ($hire['item_id'] !== null ? (int) $hire['item_id'] : null);
        if ($changed) {
            // Staff may move a hire to any day, but still only to open days (no notice or how-far-ahead limit applies to them)
            HireRules::period(config: ['minNoticeDays' => -100000, 'maxDaysAhead' => 100000] + $this->rules(), startsOn: $startsOn, endsOn: $endsOn, today: new \DateTimeImmutable('today'));
        }

        $isConfirmed = $hire['status'] === 'confirmed';
        if ($isConfirmed) $this->refuseIfClash(model: $model, hire: $hire, itemId: $itemValue, startsOn: $startsOn, endsOn: $endsOn, turnaroundDays: $turnaround);

        $sequence = (int) $hire['ics_sequence'] + ($isConfirmed && $changed ? 1 : 0);
        $notes = \trim((string) ($input['admin_notes'] ?? ''));

        $model->reschedule(id: $id, groupId: (int) $group['id'], itemId: $itemValue, startsOn: $startsOn, endsOn: $endsOn, adminNotes: $notes !== '' ? $notes : null, sequence: $sequence);
        $this->logChange(action: 'Updated', id: $id, name: $hire['customer_name']);

        $tellCustomer = $isConfirmed && $changed;
        if ($tellCustomer) (new HireNotifier($this->osmium))->changed($model->get($id));

        return ['success' => true, 'id' => $id];
    }

    private function deleteHire(int $id): array
    {
        $model = new Hire($this->osmium->dataSource);
        $hire = $model->get($id);
        $missing = $hire === null || $hire['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Hire not found');

        $model->softDelete($id);
        $this->logChange(action: 'Deleted', id: $id, name: $hire['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function restoreHire(int $id): array
    {
        $model = new Hire($this->osmium->dataSource);
        $hire = $model->get($id);
        if (!$hire) throw new \InvalidArgumentException('Hire not found');

        $model->restore($id);
        $this->logChange(action: 'Restored', id: $id, name: $hire['customer_name']);

        return ['success' => true, 'id' => $id];
    }

    private function permanentlyDelete(int $id): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $model = new Hire($this->osmium->dataSource);
        $hire = $model->get($id);
        if (!$hire) throw new \InvalidArgumentException('Hire not found');

        $this->logChange(action: 'Permanently deleted', id: $id, name: $hire['customer_name']);
        $model->hardDelete($id);

        return ['success' => true, 'id' => $id];
    }

    private function purgeDeleted(): array
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Unauthorized');

        $count = (new Hire($this->osmium->dataSource))->purgeDeleted();
        $this->admin->model->changelog->log(description: "Purged {$count} deleted hire(s)", recordType: self::RECORD_TYPE, recordId: 0);

        return ['success' => true, 'count' => $count];
    }

    /**
     * @param list<string> $allowed
     */
    private function findOpen(Hire $model, int $id, array $allowed, string $verb): array
    {
        $hire = $model->get($id);
        $missing = $hire === null || $hire['deleted_at'] !== null;
        if ($missing) throw new \InvalidArgumentException('Hire not found');

        $wrongState = !\in_array($hire['status'], $allowed, true);
        if ($wrongState) throw new \InvalidArgumentException('This hire is ' . $hire['status'] . ' and cannot be ' . $verb);

        return $hire;
    }

    private function refuseIfClash(Hire $model, array $hire, ?int $itemId, string $startsOn, string $endsOn, int $turnaroundDays): void
    {
        if ($itemId === null) return;

        $item = (new HireItem($this->osmium->dataSource))->get($itemId);
        $reason = HireRules::unavailableReason($item, $startsOn, HireRules::heldUntil($endsOn, $turnaroundDays));
        if ($reason !== null) throw new \InvalidArgumentException($reason . '. Change the days or the item first.');

        $clash = $model->findOverlap(excludeId: (int) $hire['id'], itemId: $itemId, startsOn: $startsOn, endsOn: $endsOn, turnaroundDays: $turnaroundDays);
        if ($clash === null) return;

        $when = (new \DateTimeImmutable($clash['starts_on']))->format('D j M') . ' to ' . (new \DateTimeImmutable($clash['ends_on']))->format('D j M');
        throw new \InvalidArgumentException('That clashes with a confirmed hire for ' . $clash['customer_name'] . ' (' . $when . '). Change the days or the item first.');
    }

    // =========================================================================
    // Groups, items and settings
    // =========================================================================

    private function saveGroup(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Name is required and limited to 100 characters');

        $turnaround = (int) ($input['turnaround_days'] ?? 0);
        $turnaroundBad = $turnaround < 0 || $turnaround > 60;
        if ($turnaroundBad) throw new \InvalidArgumentException('Turnaround must be between 0 and 60 days');

        $description = $this->nullIfEmpty((string) ($input['description'] ?? ''));
        $price = $this->nullIfEmpty((string) ($input['price_text'] ?? ''));
        $tooLong = \mb_strlen((string) $description) > 255 || \mb_strlen((string) $price) > 50;
        if ($tooLong) throw new \InvalidArgumentException('Description is limited to 255 characters and price to 50');

        $details = ['name' => $name, 'description' => $description, 'priceText' => $price, 'turnaroundDays' => $turnaround, 'enabled' => !empty($input['enabled']), 'sortOrder' => \max(0, (int) ($input['sort_order'] ?? 0))];
        $model = new HireGroup($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create($details) : $id;
        if (!$isNew) $model->update($id, $details);
        $this->admin->model->changelog->log(description: ($isNew ? 'Created' : 'Updated') . ' hire group: ' . $name, recordType: 'hire_group', recordId: $savedId);

        return ['success' => true, 'id' => $savedId];
    }

    private function deleteGroup(int $id): array
    {
        $model = new HireGroup($this->osmium->dataSource);
        $group = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => (int) $row['id'] === $id))[0] ?? null;
        if ($group === null) throw new \InvalidArgumentException('Not found');

        $inUse = (int) $group['hire_count'] > 0 || (int) $group['item_count'] > 0;
        if ($inUse) throw new \InvalidArgumentException('It has items or hires, so it cannot be deleted. Turn it off instead.');

        $model->delete($id);
        $this->admin->model->changelog->log(description: 'Deleted hire group: ' . $group['name'], recordType: 'hire_group', recordId: $id);

        return ['success' => true, 'id' => $id];
    }

    private function saveItem(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $nameBad = $name === '' || \mb_strlen($name) > 100;
        if ($nameBad) throw new \InvalidArgumentException('Name is required and limited to 100 characters');

        $reference = $this->nullIfEmpty((string) ($input['reference'] ?? ''));
        $referenceBad = \mb_strlen((string) $reference) > 60;
        if ($referenceBad) throw new \InvalidArgumentException('Reference is limited to 60 characters');

        $groupId = (int) ($input['group_id'] ?? 0);
        if ((new HireGroup($this->osmium->dataSource))->get($groupId) === null) throw new \InvalidArgumentException('Choose a group for the item');

        $unavailable = $this->validUnavailable($input['unavailable'] ?? []);
        $model = new HireItem($this->osmium->dataSource);
        $id = (int) ($input['id'] ?? 0);
        $enabled = !empty($input['enabled']);
        $sortOrder = \max(0, (int) ($input['sort_order'] ?? 0));

        $isNew = $id === 0;
        if (!$isNew && $model->get($id) === null) throw new \InvalidArgumentException('Not found');

        $savedId = $isNew ? $model->create(groupId: $groupId, name: $name, reference: $reference, enabled: $enabled, sortOrder: $sortOrder, unavailable: $unavailable) : $id;
        if (!$isNew) $model->update(id: $id, groupId: $groupId, name: $name, reference: $reference, enabled: $enabled, sortOrder: $sortOrder, unavailable: $unavailable);
        $this->admin->model->changelog->log(description: ($isNew ? 'Created' : 'Updated') . ' hire item: ' . $name, recordType: 'hire_item', recordId: $savedId);

        return ['success' => true, 'id' => $savedId];
    }

    /** @return ?string JSON list of {from, to, reason}, null when there is none */
    private function validUnavailable(mixed $rows): ?string
    {
        $list = [];
        $rows = \is_array($rows) ? \array_slice($rows, 0, 100) : [];
        foreach ($rows as $row) {
            $from = \trim((string) ($row['from'] ?? ''));
            $to = \trim((string) ($row['to'] ?? '')) ?: $from;
            $empty = $from === '' && \trim((string) ($row['to'] ?? '')) === '';
            if ($empty) continue;

            $datesBad = \DateTimeImmutable::createFromFormat('!Y-m-d', $from) === false || \DateTimeImmutable::createFromFormat('!Y-m-d', $to) === false || $to < $from;
            if ($datesBad) throw new \InvalidArgumentException('Each unavailable period needs a first day, and a last day that is not earlier');

            $list[] = ['from' => $from, 'to' => $to, 'reason' => \mb_substr(\trim((string) ($row['reason'] ?? '')), 0, 100)];
        }

        return $list === [] ? null : \json_encode($list);
    }

    private function deleteItem(int $id): array
    {
        $model = new HireItem($this->osmium->dataSource);
        $item = \array_values(\array_filter($model->listAll(), static fn(array $row): bool => (int) $row['id'] === $id))[0] ?? null;
        if ($item === null) throw new \InvalidArgumentException('Not found');

        $inUse = (int) $item['hire_count'] > 0;
        if ($inUse) throw new \InvalidArgumentException('Hires use this, so it cannot be deleted. Turn it off instead.');

        $model->delete($id);
        $this->admin->model->changelog->log(description: 'Deleted hire item: ' . $item['name'], recordType: 'hire_item', recordId: $id);

        return ['success' => true, 'id' => $id];
    }

    private function saveSettings(array $input): array
    {
        HireConfig::save([
            'minNoticeDays' => $input['min_notice_days'] ?? -1,
            'maxDaysAhead' => $input['max_days_ahead'] ?? 0,
            'notifyEmails' => (string) ($input['notify_emails'] ?? ''),
        ]);
        $this->admin->model->changelog->log(description: 'Updated hire settings', recordType: 'settings', recordId: 0);

        return ['success' => true];
    }

    // =========================================================================
    // Formatting
    // =========================================================================

    private function formatHire(array $row): array
    {
        $first = new \DateTimeImmutable($row['starts_on']);
        $last = new \DateTimeImmutable($row['ends_on']);
        $days = (int) $first->diff($last)->days + 1;

        return [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'statusBadge' => $this->statusBadge($row['status']),
            'whenText' => $first->format('D j M Y') . ($days > 1 ? ' to ' . $last->format('D j M Y') . ' (' . $days . ' days)' : ''),
            'customerHtml' => '<strong>' . \htmlspecialchars((string) $row['customer_name']) . '</strong><br><small>' . \htmlspecialchars((string) $row['customer_email']) . ($row['customer_phone'] ? ' &middot; ' . \htmlspecialchars((string) $row['customer_phone']) : '') . '</small>',
            'groupName' => $row['group_name'] ?? '',
            'itemName' => $row['item_name'] ?? '',
            'groupId' => (int) $row['group_id'],
            'itemId' => $row['item_id'] !== null ? (int) $row['item_id'] : '',
            'customerName' => (string) $row['customer_name'],
            'customerEmail' => (string) $row['customer_email'],
            'customerPhone' => (string) ($row['customer_phone'] ?? ''),
            'start' => $row['starts_on'],
            'end' => $row['ends_on'],
            'turnaroundDays' => (int) ($row['turnaround_days'] ?? 0),
            'customerNotes' => $row['customer_notes'] ?? '',
            'adminNotes' => $row['admin_notes'] ?? '',
            'pickupDetails' => $row['pickup_details'] ?? '',
        ];
    }

    private function formatDeleted(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['customer_name'] . ' (' . $row['reference'] . ')',
            'deletedDate' => \date('d M Y', \strtotime((string) $row['deleted_at'])),
            'changed_by' => $row['changed_by'] ?? null,
            'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
        ];
    }

    private function formatGroup(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'] ?? '',
            'priceText' => $row['price_text'] ?? '',
            'turnaroundDays' => (int) $row['turnaround_days'],
            'enabled' => (bool) $row['enabled'],
            'sortOrder' => (int) $row['sort_order'],
            'itemCount' => (int) $row['item_count'],
            'hireCount' => (int) $row['hire_count'],
            'enabledBadge' => $row['enabled'] ? '<span class="badge bg-label-success">On</span>' : '<span class="badge bg-label-secondary">Off</span>',
        ];
    }

    private function formatItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'groupId' => (int) $row['group_id'],
            'groupName' => $row['group_name'] ?? '',
            'name' => $row['name'],
            'reference' => $row['reference'] ?? '',
            'enabled' => (bool) $row['enabled'],
            'sortOrder' => (int) $row['sort_order'],
            // Kept as the stored JSON text: the list prints every field as text; the page script parses it
            'unavailable' => (string) ($row['unavailable'] ?? ''),
            'hireCount' => (int) $row['hire_count'],
            'enabledBadge' => $row['enabled'] ? '<span class="badge bg-label-success">On</span>' : '<span class="badge bg-label-secondary">Off</span>',
        ];
    }

    private function statusBadge(string $status): string
    {
        $colour = match ($status) {
            'requested' => 'warning',
            'confirmed' => 'success',
            default => 'secondary',
        };

        return '<span class="badge bg-label-' . $colour . '">' . \ucfirst($status) . '</span>';
    }

    private function nullIfEmpty(string $value): ?string
    {
        $trimmed = \trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function logChange(string $action, int $id, string $name): void
    {
        $this->admin->model->changelog->log(
            description: $action . ': ' . $name,
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );
    }
}
