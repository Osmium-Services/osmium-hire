<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Hire lists, rendered by Osmium\Modules\Admin\Core\ListView. Row fields are prepared by
 * HireController::formatHire(), formatGroup(), formatItem() and formatDeleted().
 */
require_once 'app/core/admin/services/ChangedByCell.php';

$changedByColumn = ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))];

return [
    'active' => [
        'id' => 'osmium-hire',
        'title' => 'Hires',
        'noun' => ['hire', 'hires'],
        'endpoint' => 'hire/action/',
        'note' => 'Requests wait at the top until you confirm or decline them. Open one to answer it; the customer is emailed each time.',
        'emptyText' => 'No hires yet.',
        'order' => null, // Server order: requests first, then latest time first
        'columns' => [
            ['key' => 'statusBadge', 'label' => 'Status', 'type' => 'raw', 'nowrap' => true],
            ['key' => 'whenText', 'label' => 'Days', 'type' => 'text', 'nowrap' => true],
            ['key' => 'customerHtml', 'label' => 'Customer', 'type' => 'raw', 'fill' => true],
            ['key' => 'groupName', 'label' => 'What', 'type' => 'text'],
            ['key' => 'itemName', 'label' => 'Item', 'type' => 'text'],
        ],
        'actions' => [
            ['label' => 'Open', 'icon' => 'bx-edit-alt', 'event' => 'edit'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete',
                'confirm' => 'Delete this hire? It can be restored from Deleted Hires. The customer is not emailed.',
                'danger' => true,
            ],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'osmium-hire-deleted',
        'title' => 'Deleted Hires',
        'noun' => ['item', 'items'],
        'endpoint' => 'hire/action/',
        'hideWhenEmpty' => false,
        'headerButton' => [
            'label' => 'Purge All',
            'icon' => 'bx-trash',
            'class' => 'btn-outline-danger',
            'action' => 'purge_deleted',
            'confirm' => 'Permanently delete all deleted hires? This cannot be undone!',
            'visible' => fn(array $rows, array $context): bool => (bool) $context['isAdmin'],
        ],
        'columns' => [
            $changedByColumn,
            ['key' => 'name', 'label' => 'Hire', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'deletedDate', 'label' => 'Deleted', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ]),

    'groups' => [
        'id' => 'osmium-hire-groups',
        'title' => 'What can be hired',
        'noun' => ['group', 'groups'],
        'endpoint' => 'hire/action/',
        'subtitle' => 'What customers ask for, such as "3 tonne digger". Each group holds the individual items.',
        'emptyText' => 'Nothing to hire yet - use Add to create the first group.',
        'headerButton' => ['label' => 'Add', 'icon' => 'bx-plus', 'class' => 'btn-primary btn-sm', 'event' => 'add-group'],
        'order' => null,
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'itemCount', 'label' => 'Items', 'type' => 'text', 'nowrap' => true],
            ['key' => 'priceText', 'label' => 'Price', 'type' => 'text', 'nowrap' => true],
            ['key' => 'turnaroundDays', 'label' => 'Turnaround days', 'type' => 'text', 'nowrap' => true],
            ['key' => 'enabledBadge', 'label' => 'On', 'type' => 'raw', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit-group'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete_group',
                'confirm' => 'Delete this group? (Not possible once it has items or hires: turn it off instead.)',
                'danger' => true,
            ],
        ],
    ],

    'items' => [
        'id' => 'osmium-hire-items',
        'title' => 'Items',
        'noun' => ['item', 'items'],
        'endpoint' => 'hire/action/',
        'subtitle' => 'Each item you own, with its serial number or registration (for you only) and any dates it is out of service.',
        'emptyText' => 'No items yet - add a group first, then add its items.',
        'headerButton' => ['label' => 'Add', 'icon' => 'bx-plus', 'class' => 'btn-primary btn-sm', 'event' => 'add-item'],
        'order' => null,
        'columns' => [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'groupName', 'label' => 'Group', 'type' => 'text', 'nowrap' => true],
            ['key' => 'reference', 'label' => 'Reference', 'type' => 'text', 'nowrap' => true],
            ['key' => 'enabledBadge', 'label' => 'On', 'type' => 'raw', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'event' => 'edit-item'],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete_item',
                'confirm' => 'Delete this item? (Not possible once it has hires: turn it off instead.)',
                'danger' => true,
            ],
        ],
    ],
];
