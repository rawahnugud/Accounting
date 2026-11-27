UPDATE requests
SET state = 'posted',
    document_id = (SELECT d.id FROM documents d WHERE d.branch_id = requests.branch_id AND d.request_key = requests.request_key),
    decision_by = (SELECT d.user_id FROM documents d WHERE d.branch_id = requests.branch_id AND d.request_key = requests.request_key)
WHERE state = 'pending' AND document_id IS NULL
  AND EXISTS (SELECT 1 FROM documents d WHERE d.branch_id = requests.branch_id AND d.request_key = requests.request_key);
