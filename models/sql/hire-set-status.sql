UPDATE {TABLE} SET status = :status, pickup_details = COALESCE(:pickup_details, pickup_details), ics_sequence = :ics_sequence WHERE id = :id
