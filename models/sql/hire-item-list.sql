SELECT I.*, G.name AS group_name, (SELECT COUNT(*) FROM {PREFIX}hires AS H WHERE H.item_id = I.id) AS hire_count
FROM {TABLE} AS I
LEFT JOIN {PREFIX}hire_groups AS G ON G.id = I.group_id
ORDER BY G.sort_order ASC, G.name ASC, I.sort_order ASC, I.name ASC
