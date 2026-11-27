"""Upgrade an existing private SQLite database, restore its snapshot, and test competing submissions."""
import os
import sqlite3
import subprocess
import tempfile
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

root = Path(__file__).resolve().parents[1]
os.chdir(root)
with tempfile.TemporaryDirectory() as tmp:
    target = Path(tmp) / "accounting.sqlite"
    storage = Path(tmp) / "storage"
    for folder in ["framework/sessions","framework/cache/data","logs"]:
        (storage / folder).mkdir(parents=True)
    env = dict(os.environ, APP_KEY="a"*32, APP_ENV="test", DB_CONNECTION="sqlite", DB_DATABASE=str(target),
               LARAVEL_STORAGE_PATH=str(storage), ACCOUNTING_BACKUPS=str(Path(tmp) / "backups"))
    old = sqlite3.connect(target)
    for path in sorted((root / "database").glob("00[0-2]*.sql")):
        old.executescript(path.read_text())
    old.executescript("""
        CREATE TABLE migrations(id INTEGER PRIMARY KEY,migration TEXT,batch INTEGER);
        INSERT INTO migrations(migration,batch) VALUES ('0001_01_01_000000_create_users_table',1),('2026_11_22_000001_create_treasury',1),('2026_11_22_000002_create_branch_staff',1);
        INSERT INTO currencies VALUES ('USD',2);
        INSERT INTO companies VALUES (1,'Synthetic','USD');
        INSERT INTO branches VALUES (1,1,'Sudan');
        INSERT INTO users(id,name,email,password) VALUES (1,'Tester','tester@example.invalid','disabled');
        INSERT INTO branch_staff VALUES (1,1,1,'cashier');
        INSERT INTO cash_accounts VALUES (1,1,1,'Cash','safe','USD'),(2,1,1,'Bank','bank','USD');
        INSERT INTO treasury_transfers(company_id,branch_id,currency,source_id,destination_id,amount_minor,request_key,actor)
        VALUES (1,1,'USD',1,2,1575,'legacy','staff:1');
    """)
    old.close()
    subprocess.run(["bash","scripts/upgrade-office.sh"],env=env,check=True)
    backup = next((Path(tmp)/"backups").glob("*.sqlite"))
    restored_path = Path(tmp)/"restored.sqlite"
    restored_path.write_bytes(backup.read_bytes())
    with sqlite3.connect(restored_path) as restored:
        assert restored.execute("PRAGMA integrity_check").fetchone()==("ok",)
        assert restored.execute("SELECT count(*) FROM migrations").fetchone()==(3,)
        assert restored.execute("SELECT amount_minor FROM treasury_transfers").fetchone()==(1575,)
    subprocess.run(["php","artisan","staff:promote","tester@example.invalid"],input=os.urandom(30).hex().encode(),env=env,check=True)
    with sqlite3.connect(target) as db:
        saved = db.execute("SELECT password FROM users WHERE id=1").fetchone()[0]
        db.execute("UPDATE users SET is_admin=0 WHERE id=1")
    subprocess.run(["php","artisan","staff:promote","tester@example.invalid","--keep-password"],stdin=subprocess.DEVNULL,env=env,check=True)
    with sqlite3.connect(target) as db:
        assert db.execute("SELECT password FROM users WHERE id=1").fetchone()==(saved,)
        assert db.execute("SELECT is_admin FROM users").fetchone()==(1,)
        assert db.execute("SELECT SUM(base_minor) FROM ledger").fetchone()==(0,)
    code = """require 'bootstrap/runtime.php'; $b=new App\\Accounting\\Books(App\\Database::open(),1,1);
    echo $b->transact(['kind'=>'opening','cash'=>'1','amount'=>'20','day'=>'2026-01-02','reference'=>'Concurrency',
    'request_key'=>'12345678-1234-4234-a234-1234567890ab'])['id'];"""
    def post(_):
        return subprocess.check_output(["php","-r",code],env=env)
    with ThreadPoolExecutor(max_workers=8) as pool:
        assert set(pool.map(post,range(32)))=={b'1'}
    with sqlite3.connect(target) as db:
        assert db.execute("SELECT COUNT(*) FROM documents").fetchone()==(1,)
        assert db.execute("SELECT SUM(native_minor) FROM ledger WHERE account='cash:1'").fetchone()==(425,)
    print("PASS legacy upgrade, private backup/restore, explicit admin promotion, 32 competing submissions and preserved balances")
