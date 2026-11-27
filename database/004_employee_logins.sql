CREATE TABLE employee_logins (
    user_id INTEGER PRIMARY KEY REFERENCES users(id),
    employee_id INTEGER NOT NULL UNIQUE REFERENCES parties(id)
);
