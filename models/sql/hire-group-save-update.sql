UPDATE {TABLE}
SET name = :name, description = :description, price_text = :price_text, turnaround_days = :turnaround_days,
    enabled = :enabled, sort_order = :sort_order
WHERE id = :id
