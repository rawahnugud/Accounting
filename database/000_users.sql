CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
    email_verified_at DATETIME, password TEXT NOT NULL, remember_token TEXT,
    created_at DATETIME, updated_at DATETIME
);
CREATE TABLE password_reset_tokens (email TEXT PRIMARY KEY, token TEXT NOT NULL, created_at DATETIME);
CREATE TABLE sessions (
    id TEXT PRIMARY KEY, user_id INTEGER, ip_address TEXT, user_agent TEXT,
    payload TEXT NOT NULL, last_activity INTEGER NOT NULL
);
CREATE INDEX sessions_user_id_index ON sessions(user_id);
CREATE INDEX sessions_last_activity_index ON sessions(last_activity);
