SELECT A.id, A.reference, A.customer_name, A.starts_on, A.ends_on
FROM {TABLE} AS A
LEFT JOIN {PREFIX}hire_groups AS G ON G.id = A.group_id
WHERE A.status IN ('requested', 'confirmed') AND A.deleted_at IS NULL AND A.id <> :id AND A.item_id = :item_id
  AND A.starts_on <= :held_to AND DATE_ADD(A.ends_on, INTERVAL COALESCE(G.turnaround_days, 0) DAY) >= :starts_on
ORDER BY A.starts_on
LIMIT 1
