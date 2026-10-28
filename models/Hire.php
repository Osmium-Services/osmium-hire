<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The hires: a customer's request and what the business did with it. Soft-deleted first, removed for good from the bin.
 */
class Hire extends Model
{
    public const STATUSES = ['requested', 'confirmed', 'declined', 'cancelled'];

    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'hires');
    }

    /** Every hire not in the bin, requests first, then newest time first, with its type and person */
    public function listAll(): array
    {
        return $this->rows('hire-list.sql');
    }

    public function listDeleted(): array
    {
        return $this->rows('hire-list-deleted.sql');
    }

    public function countRequested(): int
    {
        $this->database->query($this->loadSqlFile('hire-count-requested.sql'));
        $row = $this->database->single();

        return (int) ($row['total'] ?? 0);
    }

    public function get(int $id): ?array
    {
        return $this->one(file: 'hire-get.sql', param: ':id', value: $id);
    }

    public function getByReference(string $reference): ?array
    {
        return $this->one(file: 'hire-get-by-reference.sql', param: ':reference', value: $reference);
    }

    /**
     * @param array{groupId: int, itemId: ?int, name: string, email: string, phone: ?string, notes: ?string} $request
     * @return array{id: int, reference: string}
     */
    public function create(array $request, string $startsOn, string $endsOn): array
    {
        $reference = $this->newReference();

        $this->database->query($this->loadSqlFile('hire-insert.sql'));
        $this->database->bind(param: ':reference', value: $reference);
        $this->database->bind(param: ':group_id', value: $request['groupId']);
        $this->database->bind(param: ':item_id', value: $request['itemId']);
        $this->database->bind(param: ':customer_name', value: $request['name']);
        $this->database->bind(param: ':customer_email', value: $request['email']);
        $this->database->bind(param: ':customer_phone', value: $request['phone']);
        $this->database->bind(param: ':customer_notes', value: $request['notes']);
        $this->database->bind(param: ':starts_on', value: $startsOn);
        $this->database->bind(param: ':ends_on', value: $endsOn);
        $this->database->execute();

        return ['id' => (int) $this->database->lastInsertId(), 'reference' => $reference];
    }

    public function reschedule(int $id, int $groupId, ?int $itemId, string $startsOn, string $endsOn, ?string $adminNotes, int $sequence): void
    {
        $this->database->query($this->loadSqlFile('hire-reschedule.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':group_id', value: $groupId);
        $this->database->bind(param: ':item_id', value: $itemId);
        $this->database->bind(param: ':starts_on', value: $startsOn);
        $this->database->bind(param: ':ends_on', value: $endsOn);
        $this->database->bind(param: ':admin_notes', value: $adminNotes);
        $this->database->bind(param: ':ics_sequence', value: $sequence);
        $this->database->execute();
    }

    /** @param ?string $pickupDetails what the business tells the customer about collection and return, kept for the emails; null keeps what is there */
    public function setStatus(int $id, string $status, int $sequence, ?string $pickupDetails = null): void
    {
        $this->database->query($this->loadSqlFile('hire-set-status.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':status', value: $status);
        $this->database->bind(param: ':pickup_details', value: $pickupDetails);
        $this->database->bind(param: ':ics_sequence', value: $sequence);
        $this->database->execute();
    }

    /**
     * What other services have stored against the hire, such as a calendar event id: service id => value.
     * Each service keeps to its own key, so one can never overwrite another's.
     *
     * @return array<string, mixed>
     */
    public static function externalRefs(array $hire): array
    {
        $decoded = \json_decode((string) ($hire['external_refs'] ?? ''), associative: true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function setExternalRef(int $id, string $serviceId, mixed $value): void
    {
        $hire = $this->get($id);
        if ($hire === null) return;

        $refs = self::externalRefs($hire);
        $refs[$serviceId] = $value;

        $this->database->query($this->loadSqlFile('hire-set-refs.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':external_refs', value: \json_encode($refs));
        $this->database->execute();
    }

    /**
     * Which item to give a request: the first one in the group that is not out of service and has nothing confirmed
     * over those days, preferring one with no other request waiting for it either so the work spreads across the items
     *
     * @param list<array<string, mixed>> $items the group's enabled items, in the order to try them
     */
    public function findFreeItem(array $items, string $startsOn, string $endsOn, int $turnaroundDays): ?array
    {
        $heldUntil = HireRules::heldUntil($endsOn, $turnaroundDays);

        $available = [];
        foreach ($items as $item) {
            $outOfService = HireRules::unavailableReason($item, $startsOn, $heldUntil) !== null;
            $taken = $outOfService ? true : $this->findOverlap(excludeId: 0, itemId: (int) $item['id'], startsOn: $startsOn, endsOn: $endsOn, turnaroundDays: $turnaroundDays) !== null;
            if (!$taken) $available[] = $item;
        }

        foreach ($available as $item) {
            $waiting = $this->findOverlap(excludeId: 0, itemId: (int) $item['id'], startsOn: $startsOn, endsOn: $endsOn, includeRequested: true, turnaroundDays: $turnaroundDays);
            if ($waiting === null) return $item;
        }

        return $available[0] ?? null;
    }

    /**
     * A hire (confirmed, or waiting too when asked) on the same item that clashes with these days, other than the hire itself
     *
     * @param int $turnaroundDays how long the item is held after endsOn for this group; never shown to customers
     */
    public function findOverlap(int $excludeId, int $itemId, string $startsOn, string $endsOn, bool $includeRequested = false, int $turnaroundDays = 0): ?array
    {
        $this->database->query($this->loadSqlFile($includeRequested ? 'hire-overlapping-pending.sql' : 'hire-overlapping.sql'));
        $this->database->bind(param: ':id', value: $excludeId);
        $this->database->bind(param: ':item_id', value: $itemId);
        $this->database->bind(param: ':starts_on', value: $startsOn);
        $this->database->bind(param: ':held_to', value: HireRules::heldUntil($endsOn, $turnaroundDays));
        $result = $this->database->single();

        return $result ?: null;
    }

    /** The same person asking for the same thing from the same day twice */
    public function isDuplicate(string $email, string $startsOn, int $groupId): bool
    {
        $this->database->query($this->loadSqlFile('hire-duplicate.sql'));
        $this->database->bind(param: ':email', value: $email);
        $this->database->bind(param: ':starts_on', value: $startsOn);
        $this->database->bind(param: ':group_id', value: $groupId);

        return (bool) $this->database->single();
    }

    public function softDelete(int $id): void
    {
        $this->run(file: 'hire-soft-delete.sql', id: $id);
    }

    public function restore(int $id): void
    {
        $this->run(file: 'hire-restore.sql', id: $id);
    }

    public function hardDelete(int $id): void
    {
        $this->run(file: 'hire-hard-delete.sql', id: $id);
    }

    /** @return int How many hires were removed */
    public function purgeDeleted(): int
    {
        $this->database->query($this->loadSqlFile('hire-purge-deleted.sql'));
        $this->database->execute();

        return $this->database->rowCount();
    }

    /** Ten characters from letters and digits that are hard to misread, unique among hires */
    private function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $reference = '';
            for ($i = 0; $i < 10; $i++) {
                $reference .= $alphabet[\random_int(0, \strlen($alphabet) - 1)];
            }
            $this->database->query($this->loadSqlFile('hire-reference-taken.sql'));
            $this->database->bind(param: ':reference', value: $reference);
            $taken = (bool) $this->database->single();
        } while ($taken);

        return $reference;
    }

    private function rows(string $file): array
    {
        $this->database->query($this->loadSqlFile($file));

        return $this->database->resultset();
    }

    private function one(string $file, string $param, int|string $value): ?array
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: $param, value: $value);
        $result = $this->database->single();

        return $result ?: null;
    }

    private function run(string $file, int $id): void
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }
}
