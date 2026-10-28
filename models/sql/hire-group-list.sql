SELECT G.*, (SELECT COUNT(*) FROM {PREFIX}hire_items AS I WHERE I.group_id = G.id) AS item_count,
       (SELECT COUNT(*) FROM {PREFIX}hires AS H WHERE H.group_id = G.id) AS hire_count
FROM {TABLE} AS G
ORDER BY G.sort_order ASC, G.name ASC
