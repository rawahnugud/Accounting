-- Lets SQLite find a document's entries directly. Without it, dashboard, form and report queries scan every entry once per document.
CREATE INDEX IF NOT EXISTS entries_document ON entries(document_id);
