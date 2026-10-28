UPDATE {TABLE}
SET group_id = :group_id, item_id = :item_id, starts_on = :starts_on, ends_on = :ends_on,
    admin_notes = :admin_notes, ics_sequence = :ics_sequence
WHERE id = :id
