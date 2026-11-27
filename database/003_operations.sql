ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0 CHECK(is_admin IN (0,1));
CREATE TABLE parties (
 id INTEGER PRIMARY KEY, branch_id INTEGER NOT NULL REFERENCES branches, kind TEXT NOT NULL CHECK(kind IN ('customer','supplier','creditor','employee')),
 name TEXT NOT NULL, currency TEXT NOT NULL REFERENCES currencies(code), detail TEXT NOT NULL DEFAULT '', UNIQUE(branch_id,kind,name)
);
CREATE TABLE rates (
 branch_id INTEGER NOT NULL REFERENCES branches, currency TEXT NOT NULL REFERENCES currencies(code),
 effective TEXT NOT NULL, rate TEXT NOT NULL, PRIMARY KEY(branch_id,currency,effective)
);
CREATE TABLE periods (branch_id INTEGER PRIMARY KEY REFERENCES branches, closed_through TEXT NOT NULL);
CREATE TABLE documents (
 id INTEGER PRIMARY KEY, branch_id INTEGER NOT NULL REFERENCES branches, user_id INTEGER NOT NULL REFERENCES users,
 kind TEXT NOT NULL, day TEXT NOT NULL, due TEXT NOT NULL, reference TEXT NOT NULL, details TEXT NOT NULL,
 related_id INTEGER REFERENCES documents, reversal_of INTEGER UNIQUE REFERENCES documents,
 request_key TEXT NOT NULL, fingerprint TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(branch_id,request_key)
);
CREATE TABLE entries (
 id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL REFERENCES documents,
 account TEXT NOT NULL, currency TEXT NOT NULL REFERENCES currencies(code), native_minor INTEGER NOT NULL,
 base_minor INTEGER NOT NULL, rate TEXT NOT NULL
);
CREATE INDEX entries_account ON entries(account,document_id);
CREATE INDEX documents_branch_date ON documents(branch_id,day);
CREATE TABLE audit_events (
 id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users, action TEXT NOT NULL, detail TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE staff_leave (
 id INTEGER PRIMARY KEY, employee_id INTEGER NOT NULL REFERENCES parties, starts TEXT NOT NULL, ends TEXT NOT NULL,
 reason TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'pending', UNIQUE(employee_id,starts,ends)
);
CREATE TABLE reconciliations (
 id INTEGER PRIMARY KEY, cash_id INTEGER NOT NULL REFERENCES cash_accounts, day TEXT NOT NULL, statement_minor INTEGER NOT NULL,
 ledger_minor INTEGER NOT NULL, user_id INTEGER NOT NULL REFERENCES users, UNIQUE(cash_id,day)
);
CREATE VIEW ledger AS
 SELECT d.branch_id,d.day,d.user_id,d.kind,d.reference,'D-'||d.id AS number,e.account,e.currency,e.native_minor,e.base_minor
 FROM entries e JOIN documents d ON d.id=e.document_id
 UNION ALL
 SELECT j.branch_id,substr(t.posted_at,1,10),CASE WHEN t.actor LIKE 'staff:%' THEN CAST(substr(t.actor,7) AS INTEGER) END,
 'transfer','Transfer','T-'||t.id,'cash:'||j.account_id,j.currency,j.debit_minor-j.credit_minor,j.debit_minor-j.credit_minor
 FROM treasury_journal j JOIN treasury_transfers t ON t.id=j.transfer_id;
CREATE TRIGGER documents_no_update BEFORE UPDATE ON documents BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER documents_no_delete BEFORE DELETE ON documents BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER entries_no_update BEFORE UPDATE ON entries BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER entries_no_delete BEFORE DELETE ON entries BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER audit_events_no_update BEFORE UPDATE ON audit_events BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER audit_events_no_delete BEFORE DELETE ON audit_events BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER rates_no_update BEFORE UPDATE ON rates BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER rates_no_delete BEFORE DELETE ON rates BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER reconciliations_no_update BEFORE UPDATE ON reconciliations BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER reconciliations_no_delete BEFORE DELETE ON reconciliations BEGIN SELECT RAISE(ABORT,'Historical entry is immutable'); END;
CREATE TRIGGER transfer_closed_period BEFORE INSERT ON treasury_transfers
WHEN EXISTS(SELECT 1 FROM periods WHERE branch_id=NEW.branch_id AND closed_through>=substr(NEW.posted_at,1,10))
BEGIN SELECT RAISE(ABORT,'This period is closed'); END;
CREATE TABLE credit_limits (
 party_id INTEGER PRIMARY KEY REFERENCES parties, limit_minor INTEGER NOT NULL CHECK(limit_minor>=0)
);
CREATE TABLE employee_terms (
 employee_id INTEGER PRIMARY KEY REFERENCES parties, job_title TEXT NOT NULL, salary_minor INTEGER NOT NULL CHECK(salary_minor>=0)
);
CREATE TABLE schedules (
 id INTEGER PRIMARY KEY, document_id INTEGER NOT NULL REFERENCES documents, due TEXT NOT NULL, amount_minor INTEGER NOT NULL CHECK(amount_minor>0),
 UNIQUE(document_id,due)
);
CREATE TABLE service_categories (name TEXT PRIMARY KEY);
INSERT INTO service_categories VALUES ('Ticket'),('Visa'),('Hotel'),('Insurance'),('Umrah'),('Transfer'),('Other');
CREATE TABLE requests (
 id INTEGER PRIMARY KEY,branch_id INTEGER NOT NULL REFERENCES branches,requested_by INTEGER NOT NULL REFERENCES users,
 request_key TEXT NOT NULL,payload TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',decision_by INTEGER REFERENCES users,
 document_id INTEGER REFERENCES documents,UNIQUE(branch_id,request_key)
);
CREATE TABLE supplier_checks (
 id INTEGER PRIMARY KEY,party_id INTEGER NOT NULL REFERENCES parties,day TEXT NOT NULL,statement_minor INTEGER NOT NULL,
 ledger_minor INTEGER NOT NULL,user_id INTEGER NOT NULL REFERENCES users,UNIQUE(party_id,day)
);
CREATE TABLE matches (
 id INTEGER PRIMARY KEY,branch_id INTEGER NOT NULL REFERENCES branches,account TEXT NOT NULL,
 debit_doc INTEGER NOT NULL REFERENCES documents,credit_doc INTEGER NOT NULL REFERENCES documents,
 amount_minor INTEGER NOT NULL CHECK(amount_minor>0),user_id INTEGER NOT NULL REFERENCES users,request_key TEXT NOT NULL,
 fx_doc INTEGER REFERENCES documents,debit_base INTEGER NOT NULL,credit_base INTEGER NOT NULL,UNIQUE(branch_id,request_key)
);
CREATE VIEW open_items AS
WITH items AS (
 SELECT d.branch_id,d.id,d.kind,d.day,d.due,d.reference,e.account,e.currency,e.native_minor AS original_minor,e.base_minor AS original_base
 FROM documents d JOIN entries e ON e.document_id=d.id
 WHERE (e.account LIKE 'customer:%' OR e.account LIKE 'supplier:%' OR e.account LIKE 'creditor:%' OR e.account LIKE 'employee:%')
 AND d.reversal_of IS NULL AND d.kind<>'allocation_fx'
 AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id)
 AND (d.related_id IS NULL OR d.kind IN ('reissue','refund'))
)
SELECT i.*, i.original_minor+COALESCE((SELECT SUM(p.native_minor) FROM entries p JOIN documents s ON s.id=p.document_id
 WHERE s.related_id=i.id AND s.kind IN ('receipt','supplier_payment','salary_payment','repayment','customer_refund','supplier_refund') AND p.account=i.account
 AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=s.id)),0)
 -COALESCE((SELECT SUM(m.amount_minor) FROM matches m WHERE m.debit_doc=i.id AND m.account=i.account),0)
 +COALESCE((SELECT SUM(m.amount_minor) FROM matches m WHERE m.credit_doc=i.id AND m.account=i.account),0) AS outstanding_minor
 FROM items i;
CREATE TABLE match_history (id INTEGER PRIMARY KEY,branch_id INTEGER NOT NULL,account TEXT NOT NULL,debit_doc INTEGER NOT NULL,credit_doc INTEGER NOT NULL,amount_minor INTEGER NOT NULL,user_id INTEGER NOT NULL,request_key TEXT NOT NULL,fx_doc INTEGER,removed_by INTEGER NOT NULL,removed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(branch_id,request_key));
CREATE TABLE company_profiles (
 company_id INTEGER PRIMARY KEY REFERENCES companies, address TEXT NOT NULL, contact TEXT NOT NULL, registration TEXT NOT NULL
);
CREATE TABLE numbering (
 branch_id INTEGER PRIMARY KEY REFERENCES branches, prefix TEXT NOT NULL UNIQUE, next_number INTEGER NOT NULL CHECK(next_number>0)
);
CREATE TABLE document_numbers (
 document_id INTEGER PRIMARY KEY REFERENCES documents, label TEXT NOT NULL UNIQUE
);
CREATE TRIGGER assign_document_number AFTER INSERT ON documents
WHEN EXISTS(SELECT 1 FROM numbering WHERE branch_id=NEW.branch_id)
BEGIN
 INSERT INTO document_numbers(document_id,label) SELECT NEW.id,prefix||'-'||printf('%06d',next_number) FROM numbering WHERE branch_id=NEW.branch_id;
 UPDATE numbering SET next_number=next_number+1 WHERE branch_id=NEW.branch_id;
END;
