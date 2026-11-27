INSERT INTO currencies(code, decimals) VALUES
    ('USD', 2), ('SDG', 2), ('AED', 2), ('SAR', 2)
ON CONFLICT(code) DO NOTHING;

CREATE TABLE statement_checks (
    id INTEGER PRIMARY KEY,
    branch_id INTEGER NOT NULL REFERENCES branches(id),
    account TEXT NOT NULL,
    currency TEXT NOT NULL REFERENCES currencies(code),
    as_of TEXT NOT NULL,
    ledger_minor INTEGER NOT NULL,
    external_minor INTEGER NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(branch_id, account, as_of, ledger_minor, external_minor)
);
