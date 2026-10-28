(function () {
    'use strict';

    const config = window.OsmiumHireConfig || {};
    const actionUrl = config.basePath + 'hire/action/';
    const DAYS_SHOWN = 14;

    function parseStored(text, fallback) {
        try {
            const value = JSON.parse(text);

            return Array.isArray(value) ? value : fallback;
        } catch (error) {
            return fallback;
        }
    }

    Object.values(config.items || {}).forEach(function (item) {
        item.unavailable = parseStored(item.unavailable, []);
    });

    function byId(id) {
        return document.getElementById(id);
    }

    function show(resultId, kind, message) {
        const box = byId(resultId);
        box.className = 'alert alert-' + kind + (resultId === 'settingsResult' ? ' mb-0 py-2' : '');
        box.textContent = message;
    }

    function clear(resultId) {
        const box = byId(resultId);
        box.className = 'alert d-none';
        box.textContent = '';
    }

    async function post(payload) {
        const response = await fetch(actionUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: window.csrfToken }, payload))
        });

        return response.json();
    }

    async function submit(event, resultId, payload) {
        event.preventDefault();
        clear(resultId);

        try {
            const result = await post(payload);
            if (result.success) {
                window.location.reload();
                return;
            }

            show(resultId, 'danger', result.error || 'Failed to save');
        } catch (error) {
            show(resultId, 'danger', 'An error occurred while saving');
        }
    }

    // The schedule page and the setup page share this script
    function on(id, event, handler) {
        const node = byId(id);
        if (node) node.addEventListener(event, handler);
    }

    function openModal(id) {
        new bootstrap.Modal(byId(id)).show();
    }

    function isoDate(date) {
        const pad = function (n) { return String(n).padStart(2, '0'); };

        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function addDays(iso, days) {
        const date = new Date(iso + 'T00:00:00');
        date.setDate(date.getDate() + days);

        return isoDate(date);
    }

    // ---------------------------------------------------------------- hire modal

    const DECISIONS = {
        confirm: { button: 'hireConfirmButton', label: 'Confirm', statuses: ['requested'], sure: false },
        decline: { button: 'hireDeclineButton', label: 'Decline request', statuses: ['requested'], sure: true },
        cancel: { button: 'hireCancelButton', label: 'Cancel hire', statuses: ['confirmed'], sure: true }
    };
    let pendingDecision = null;
    let pendingTimer = null;
    let currentStatus = '';

    function resetDecisionButtons(status) {
        pendingDecision = null;
        window.clearTimeout(pendingTimer);
        byId('hireDeclineBox').classList.add('d-none');
        byId('hirePickupBox').classList.toggle('d-none', status !== 'requested');
        Object.keys(DECISIONS).forEach(function (name) {
            const decision = DECISIONS[name];
            const button = byId(decision.button);
            button.textContent = decision.label;
            button.classList.toggle('d-none', decision.statuses.indexOf(status) === -1);
            button.disabled = false;
        });

        const save = byId('hireSaveButton');
        save.classList.toggle('btn-primary', status !== 'requested');
        save.classList.toggle('btn-outline-primary', status === 'requested');
    }

    // Decline and Cancel email the customer, so the first click asks "sure?" and the second one does it
    async function decide(name) {
        const decision = DECISIONS[name];
        const button = byId(decision.button);

        if (decision.sure && pendingDecision !== name) {
            resetDecisionButtons(currentStatus);
            pendingDecision = name;
            button.textContent = 'Click again to ' + decision.label.toLowerCase();
            byId('hireDeclineBox').classList.remove('d-none');
            byId('hirePickupBox').classList.add('d-none');
            pendingTimer = window.setTimeout(function () { resetDecisionButtons(currentStatus); }, 120000);
            return;
        }

        clear('hireResult');
        button.disabled = true;
        try {
            const payload = { action: name, id: byId('hireId').value };
            payload.message = name === 'confirm' ? byId('hirePickup').value : byId('hireDeclineMessage').value;
            const result = await post(payload);
            if (result.success) {
                window.location.reload();
                return;
            }

            show('hireResult', 'danger', result.error || 'That did not work');
        } catch (error) {
            show('hireResult', 'danger', 'An error occurred');
        }
        resetDecisionButtons(currentStatus);
    }

    // The item dropdown lists the chosen group's items; "Any free one" lets the system choose
    function fillItems(groupId, selected) {
        const select = byId('hireItem');
        select.innerHTML = '';
        const any = document.createElement('option');
        any.value = '0';
        any.textContent = 'Any free one';
        select.appendChild(any);
        Object.values(config.items || {}).forEach(function (item) {
            if (String(item.groupId) !== String(groupId)) return;
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name + (item.reference ? ' (' + item.reference + ')' : '');
            select.appendChild(option);
        });
        select.value = selected ? String(selected) : '0';
    }

    function openNewHire(startIso, itemId) {
        clear('hireResult');
        byId('hireForm').reset();
        byId('hireId').value = '';
        currentStatus = '';
        resetDecisionButtons('');
        byId('hirePickupBox').classList.remove('d-none');
        byId('hireModalTitle').textContent = 'New hire';
        byId('hireNewFields').classList.remove('d-none');
        byId('hireCustomer').classList.add('d-none');
        byId('hireWho').classList.add('d-none');
        byId('hireTheirNotes').textContent = '';
        byId('hireNotesLabel').textContent = 'Notes (kept on the booking)';
        byId('hireSaveButton').textContent = 'Book it';
        byId('hireSaveButton').classList.add('btn-primary');
        byId('hireSaveButton').classList.remove('btn-outline-primary');
        const item = itemId ? config.items[itemId] : null;
        if (item) byId('hireGroup').value = item.groupId;
        fillItems(byId('hireGroup').value, itemId || 0);
        byId('hireStart').value = startIso;
        byId('hireEnd').value = startIso;
        openModal('hireModal');
    }

    function openHire(hire) {
        clear('hireResult');
        byId('hireModalTitle').textContent = 'Hire';
        byId('hireNewFields').classList.add('d-none');
        byId('hireCustomer').classList.remove('d-none');
        byId('hireWho').classList.remove('d-none');
        byId('hireNotesLabel').textContent = 'Your notes (never shown to the customer)';
        byId('hireSaveButton').textContent = 'Save changes';
        currentStatus = hire.status;
        resetDecisionButtons(hire.status);
        byId('hireId').value = hire.id;
        byId('hireCustomer').textContent = [hire.customerName, hire.customerEmail, hire.customerPhone].filter(Boolean).join(' · ');
        byId('hireWho').textContent = hire.reference + ' · ' + (hire.status === 'requested' ? 'waiting for you' : hire.status);
        byId('hireTheirNotes').textContent = hire.customerNotes ? 'They wrote: ' + hire.customerNotes : '';
        byId('hireGroup').value = hire.groupId;
        fillItems(hire.groupId, hire.itemId);
        byId('hireStart').value = hire.start;
        byId('hireEnd').value = hire.end;
        byId('hireNotes').value = hire.adminNotes;
        byId('hirePickup').value = hire.pickupDetails || '';
        openModal('hireModal');
    }

    // ---------------------------------------------------------------- schedule

    const scheduleState = { date: '' };

    function titleFor(first) {
        const last = addDays(first, DAYS_SHOWN - 1);
        const fmt = { day: 'numeric', month: 'short', year: 'numeric' };

        return new Date(first + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ' to ' + new Date(last + 'T00:00:00').toLocaleDateString('en-GB', fmt);
    }

    function itemDayState(item, iso) {
        const off = item.unavailable.some(function (range) { return range.from <= iso && iso <= range.to; });
        if (off) return { cls: 'sched-off' };

        for (const hire of Object.values(config.hires || {})) {
            if (String(hire.itemId) !== String(item.id)) continue;
            if (hire.status !== 'requested' && hire.status !== 'confirmed') continue;
            if (hire.start <= iso && iso <= hire.end) return { hire: hire };
            if (hire.end < iso && iso <= addDays(hire.end, hire.turnaroundDays)) return { cls: 'sched-turn' };
        }

        return {};
    }

    function draw() {
        const grid = byId('scheduleGrid');
        const first = scheduleState.date;
        byId('scheduleTitle').textContent = titleFor(first);
        grid.innerHTML = '';

        const items = Object.values(config.items || {}).filter(function (item) { return item.enabled; });
        if (items.length === 0) {
            const note = document.createElement('div');
            note.className = 'sched-note';
            note.textContent = 'No items yet. Add groups and items under Services > Osmium Hire.';
            grid.appendChild(note);
            return;
        }

        const table = document.createElement('div');
        table.className = 'hp';
        table.style.gridTemplateColumns = '9rem repeat(' + DAYS_SHOWN + ', minmax(2.2rem, 1fr))';
        table.appendChild(document.createElement('div'));
        const days = [];
        for (let i = 0; i < DAYS_SHOWN; i++) {
            const iso = addDays(first, i);
            days.push(iso);
            const date = new Date(iso + 'T00:00:00');
            const head = document.createElement('div');
            head.className = 'hp-head';
            head.textContent = date.toLocaleDateString('en-GB', { weekday: 'short' });
            const small = document.createElement('small');
            small.textContent = date.getDate();
            head.appendChild(small);
            table.appendChild(head);
        }

        let lastGroup = null;
        items.forEach(function (item) {
            if (item.groupId !== lastGroup) {
                lastGroup = item.groupId;
                const label = document.createElement('div');
                label.className = 'hp-group';
                label.textContent = item.groupName;
                table.appendChild(label);
            }
            const name = document.createElement('div');
            name.className = 'hp-name';
            name.textContent = item.name;
            if (item.reference) {
                const small = document.createElement('small');
                small.textContent = item.reference;
                name.appendChild(small);
            }
            table.appendChild(name);

            let index = 0;
            while (index < days.length) {
                const iso = days[index];
                const state = itemDayState(item, iso);
                if (state.hire) {
                    // One bar across the visible days of this hire
                    let span = 1;
                    while (index + span < days.length && days[index + span] <= state.hire.end) span++;
                    const cell = document.createElement('div');
                    cell.className = 'hp-cell';
                    cell.style.gridColumn = 'span ' + span;
                    const bar = document.createElement('div');
                    bar.className = 'hp-bar sched-' + state.hire.status;
                    bar.textContent = state.hire.customerName;
                    bar.title = state.hire.reference + ' · ' + state.hire.whenText;
                    bar.addEventListener('click', function () { openHire(state.hire); });
                    cell.appendChild(bar);
                    table.appendChild(cell);
                    index += span;
                    continue;
                }

                const cell = document.createElement('div');
                const weekday = new Date(iso + 'T00:00:00').getDay() || 7;
                const closed = !(config.openDays || {})[weekday];
                cell.className = 'hp-cell ' + (state.cls || (closed ? 'sched-closed' : ''));
                cell.addEventListener('dblclick', function () { openNewHire(iso, item.id); });
                table.appendChild(cell);
                index++;
            }
        });
        grid.appendChild(table);
    }

    function initSchedule() {
        const input = byId('scheduleDate');
        const set = function (iso) { scheduleState.date = iso; input.value = iso; draw(); };
        on('scheduleToday', 'click', function () { set(isoDate(new Date())); });
        on('scheduleNew', 'click', function () { openNewHire(scheduleState.date, 0); });
        on('schedulePrev', 'click', function () { set(addDays(scheduleState.date, -7)); });
        on('scheduleNext', 'click', function () { set(addDays(scheduleState.date, 7)); });
        input.addEventListener('change', function () { if (input.value) set(input.value); });
        set(isoDate(new Date()));
    }

    // ---------------------------------------------------------------- groups and items

    function openGroup(group) {
        clear('groupResult');
        const isEdit = Boolean(group);
        byId('groupModalTitle').textContent = isEdit ? 'Edit group' : 'Add a group';
        byId('groupId').value = isEdit ? group.id : '';
        byId('groupName').value = isEdit ? group.name : '';
        byId('groupDescription').value = isEdit ? group.description : '';
        byId('groupPrice').value = isEdit ? group.priceText : '';
        byId('groupOrder').value = isEdit ? group.sortOrder : 0;
        byId('groupTurnaround').value = isEdit ? group.turnaroundDays : 1;
        byId('groupEnabled').checked = isEdit ? group.enabled : true;
        showChooseName();
        openModal('groupModal');
    }

    function showChooseName() {
        byId('groupChooseName').textContent = byId('groupName').value.trim() || 'this';
    }

    function addUnavailableRow(range) {
        const row = document.createElement('div');
        row.className = 'row g-2 mb-2 align-items-center unavailable-row';
        const make = function (type, cls, value, col, placeholder) {
            const wrap = document.createElement('div');
            wrap.className = col;
            const input = document.createElement('input');
            input.type = type;
            input.className = 'form-control form-control-sm ' + cls;
            input.value = value || '';
            if (placeholder) input.placeholder = placeholder;
            wrap.appendChild(input);
            row.appendChild(wrap);
        };
        make('date', 'u-from', range.from, 'col-md-3');
        make('date', 'u-to', range.to, 'col-md-3');
        make('text', 'u-reason', range.reason, 'col-md-5', 'Reason (e.g. hydraulic repair)');
        const cell = document.createElement('div');
        cell.className = 'col-md-1';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-link text-danger px-0';
        remove.textContent = 'Remove';
        remove.addEventListener('click', function () { row.remove(); });
        cell.appendChild(remove);
        row.appendChild(cell);
        byId('itemUnavailable').appendChild(row);
    }

    function collectUnavailable() {
        return Array.from(document.querySelectorAll('.unavailable-row')).map(function (row) {
            return {
                from: row.querySelector('.u-from').value,
                to: row.querySelector('.u-to').value,
                reason: row.querySelector('.u-reason').value
            };
        });
    }

    function openItem(item) {
        clear('itemResult');
        const isEdit = Boolean(item);
        byId('itemModalTitle').textContent = isEdit ? 'Edit item' : 'Add an item';
        byId('itemId').value = isEdit ? item.id : '';
        byId('itemName').value = isEdit ? item.name : '';
        byId('itemReference').value = isEdit ? item.reference : '';
        byId('itemOrder').value = isEdit ? item.sortOrder : 0;
        byId('itemEnabled').checked = isEdit ? item.enabled : true;
        if (isEdit) byId('itemGroup').value = item.groupId;
        byId('itemUnavailable').innerHTML = '';
        (isEdit ? item.unavailable : []).forEach(addUnavailableRow);
        openModal('itemModal');
    }

    function init() {
        if (byId('schedule')) initSchedule();

        document.addEventListener('list:action', function (e) {
            const action = e.detail.action;
            if (action === 'edit') openHire(config.hires[e.detail.id]);
            if (action === 'add-group') openGroup(null);
            if (action === 'edit-group') openGroup(config.groups[e.detail.id]);
            if (action === 'add-item') openItem(null);
            if (action === 'edit-item') openItem(config.items[e.detail.id]);
        });

        Object.keys(DECISIONS).forEach(function (name) {
            on(DECISIONS[name].button, 'click', function () { decide(name); });
        });
        on('hireGroup', 'change', function () { fillItems(byId('hireGroup').value, 0); });

        on('hireForm', 'submit', function (e) {
            const creating = byId('hireId').value === '';
            const shared = {
                group_id: byId('hireGroup').value,
                item_id: byId('hireItem').value,
                start: byId('hireStart').value,
                end: byId('hireEnd').value
            };
            submit(e, 'hireResult', creating ? Object.assign({
                action: 'create',
                name: byId('hireName').value,
                phone: byId('hirePhone').value,
                email: byId('hireEmail').value,
                notes: byId('hireNotes').value,
                pickup_details: byId('hirePickup').value
            }, shared) : Object.assign({
                action: 'reschedule',
                id: byId('hireId').value,
                admin_notes: byId('hireNotes').value
            }, shared));
        });

        on('groupForm', 'submit', function (e) {
            submit(e, 'groupResult', {
                action: 'save_group',
                id: byId('groupId').value,
                name: byId('groupName').value,
                description: byId('groupDescription').value,
                price_text: byId('groupPrice').value,
                turnaround_days: byId('groupTurnaround').value,
                sort_order: byId('groupOrder').value,
                enabled: byId('groupEnabled').checked
            });
        });
        on('groupName', 'input', showChooseName);

        on('itemForm', 'submit', function (e) {
            submit(e, 'itemResult', {
                action: 'save_item',
                id: byId('itemId').value,
                group_id: byId('itemGroup').value,
                name: byId('itemName').value,
                reference: byId('itemReference').value,
                sort_order: byId('itemOrder').value,
                enabled: byId('itemEnabled').checked,
                unavailable: collectUnavailable()
            });
        });
        on('itemAddUnavailable', 'click', function () { addUnavailableRow({}); });

        on('settingsForm', 'submit', function (e) {
            submit(e, 'settingsResult', {
                action: 'save_settings',
                min_notice_days: byId('settingsNotice').value,
                max_days_ahead: byId('settingsAhead').value,
                notify_emails: byId('settingsEmails').value
            });
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
