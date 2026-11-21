CREATE TABLE currencies (
    code TEXT PRIMARY KEY NOT NULL CHECK(code GLOB '[A-Z][A-Z][A-Z]'),
    decimals INTEGER NOT NULL CHECK(typeof(decimals) = 'integer' AND decimals BETWEEN 0 AND 6)
);
CREATE TABLE companies (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    reporting_currency TEXT NOT NULL REFERENCES currencies(code),
    UNIQUE(id, reporting_currency)
);
CREATE TABLE branches (
    id INTEGER PRIMARY KEY,
    company_id INTEGER NOT NULL REFERENCES companies(id),
    name TEXT NOT NULL,
    UNIQUE(company_id, id)
);
CREATE TABLE cash_accounts (
    id INTEGER PRIMARY KEY,
    company_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    kind TEXT NOT NULL CHECK(kind IN ('bank','safe')),
    currency TEXT NOT NULL REFERENCES currencies(code),
    FOREIGN KEY(company_id, branch_id) REFERENCES branches(company_id, id),
    UNIQUE(company_id, branch_id, currency, id)
);
-- A transfer is one append-only fact. Its two journal lines always share the same magnitude.
CREATE TABLE treasury_transfers (
    id INTEGER PRIMARY KEY,
    company_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    currency TEXT NOT NULL,
    source_id INTEGER NOT NULL,
    destination_id INTEGER NOT NULL CHECK(destination_id <> source_id),
    amount_minor INTEGER NOT NULL CHECK(typeof(amount_minor) = 'integer' AND amount_minor > 0),
    request_key TEXT NOT NULL CHECK(length(trim(request_key)) BETWEEN 1 AND 128),
    actor TEXT NOT NULL CHECK(length(trim(actor)) BETWEEN 1 AND 128),
    posted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(company_id, request_key),
    FOREIGN KEY(company_id, currency) REFERENCES companies(id, reporting_currency),
    FOREIGN KEY(company_id, branch_id, currency, source_id)
        REFERENCES cash_accounts(company_id, branch_id, currency, id),
    FOREIGN KEY(company_id, branch_id, currency, destination_id)
        REFERENCES cash_accounts(company_id, branch_id, currency, id)
);
CREATE TRIGGER transfers_no_update BEFORE UPDATE ON treasury_transfers
BEGIN SELECT RAISE(ABORT,'Posted transfers are immutable'); END;
CREATE TRIGGER transfers_no_delete BEFORE DELETE ON treasury_transfers
BEGIN SELECT RAISE(ABORT,'Posted transfers are immutable'); END;
CREATE TRIGGER currency_precision_locked BEFORE UPDATE OF decimals ON currencies
WHEN OLD.decimals <> NEW.decimals AND EXISTS(SELECT 1 FROM treasury_transfers WHERE currency = OLD.code)
BEGIN SELECT RAISE(ABORT,'Currency precision is already in use'); END;
CREATE VIEW treasury_journal AS
SELECT id AS transfer_id, company_id, branch_id, currency, destination_id AS account_id,
       amount_minor AS debit_minor, 0 AS credit_minor FROM treasury_transfers
UNION ALL
SELECT id, company_id, branch_id, currency, source_id, 0, amount_minor FROM treasury_transfers;
