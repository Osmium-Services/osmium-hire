<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * One thing that can be hired: a particular digger, ladder or van, in a group. Its reference (serial number,
 * registration, VIN) is for the business only. Its unavailable list is JSON of {from, to, reason} ranges (repairs, servicing).
 */
class HireItem extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'hire_items');
    }

    public function listAll(): array
    {
        $this->database->query($this->loadSqlFile('hire-item-list.sql'));

        return $this->database->resultset();
    }

    public function get(int $id): ?array
    {
        $this->database->query($this->loadSqlFile('hire-item-get.sql'));
        $this->database->bind(param: ':id', value: $id);
        $result = $this->database->single();

        return $result ?: null;
    }

    public function create(int $groupId, string $name, ?string $reference, bool $enabled, int $sortOrder, ?string $unavailable = null): int
    {
        $this->database->query($this->loadSqlFile('hire-item-insert.sql'));
        $this->bind(groupId: $groupId, name: $name, reference: $reference, enabled: $enabled, sortOrder: $sortOrder, unavailable: $unavailable);
        $this->database->execute();

        return (int) $this->database->lastInsertId();
    }

    public function update(int $id, int $groupId, string $name, ?string $reference, bool $enabled, int $sortOrder, ?string $unavailable = null): void
    {
        $this->database->query($this->loadSqlFile('hire-item-update.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->bind(groupId: $groupId, name: $name, reference: $reference, enabled: $enabled, sortOrder: $sortOrder, unavailable: $unavailable);
        $this->database->execute();
    }

    public function delete(int $id): void
    {
        $this->database->query($this->loadSqlFile('hire-item-delete.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }

    private function bind(int $groupId, string $name, ?string $reference, bool $enabled, int $sortOrder, ?string $unavailable): void
    {
        $this->database->bind(param: ':group_id', value: $groupId);
        $this->database->bind(param: ':name', value: $name);
        $this->database->bind(param: ':reference', value: $reference);
        $this->database->bind(param: ':enabled', value: $enabled ? 1 : 0);
        $this->database->bind(param: ':unavailable', value: $unavailable);
        $this->database->bind(param: ':sort_order', value: $sortOrder);
    }
}
