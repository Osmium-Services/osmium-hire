SELECT B.*, T.name AS group_name, T.turnaround_days, R.name AS item_name, R.reference AS item_reference
FROM {TABLE} AS B
LEFT JOIN {PREFIX}hire_groups AS T ON T.id = B.group_id
LEFT JOIN {PREFIX}hire_items AS R ON R.id = B.item_id
WHERE B.id = :id
