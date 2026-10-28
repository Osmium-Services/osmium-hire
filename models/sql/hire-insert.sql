INSERT INTO {TABLE}
    (reference, group_id, item_id, customer_name, customer_email, customer_phone, customer_notes, starts_on, ends_on, status)
VALUES
    (:reference, :group_id, :item_id, :customer_name, :customer_email, :customer_phone, :customer_notes, :starts_on, :ends_on, 'requested')
