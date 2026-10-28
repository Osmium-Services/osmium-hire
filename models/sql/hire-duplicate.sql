SELECT id FROM {TABLE}
WHERE customer_email = :email AND starts_on = :starts_on AND group_id = :group_id
  AND status IN ('requested', 'confirmed') AND deleted_at IS NULL
LIMIT 1
