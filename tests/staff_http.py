"""Local integration check. Run after composer install. Requires Python and PHP CLI."""
import http.cookiejar
import os
import re
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
from pathlib import Path
from urllib import error, parse, request

os.chdir(Path(__file__).resolve().parents[1])
php = os.environ.get("PHP_BINARY", "php")

with tempfile.TemporaryDirectory() as temp:
    db = Path(temp) / "test.sqlite"
    db.touch()
    env = dict(os.environ, APP_ENV="local", APP_DEBUG="false", APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
               DB_CONNECTION="sqlite", DB_DATABASE=str(db), SESSION_DRIVER="file", CACHE_STORE="file",
               LARAVEL_STORAGE_PATH=temp, LOG_CHANNEL="errorlog")
    for folder in ["framework/sessions", "framework/views", "framework/cache/data", "logs"]:
        (Path(temp) / folder).mkdir(parents=True)
    subprocess.run([php, "artisan", "migrate", "--force"], env=env, check=True)
    password = secrets.token_urlsafe(18)
    hashed = subprocess.check_output([php, "-r", "echo password_hash(trim(fgets(STDIN)), PASSWORD_BCRYPT);"],
                                     input=password.encode()).decode()
    conn = sqlite3.connect(db)
    conn.executescript("""
        INSERT INTO currencies VALUES ('USD',2);
        INSERT INTO companies VALUES (1,'Example','USD');
        INSERT INTO branches VALUES (1,1,'Main'),(2,1,'Other');
        INSERT INTO cash_accounts VALUES (1,1,1,'Bank','bank','USD'),(2,1,1,'Safe','safe','USD');
    """)
    for user_id, role in [(1,"viewer"), (2,"cashier")]:
        conn.execute("INSERT INTO users(id,name,email,password) VALUES (?,?,?,?)",
                     (user_id, role, role+"@example.test", hashed))
        conn.execute("INSERT INTO branch_staff VALUES (?,1,1,?)", (user_id,role))
    conn.commit()
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    origin = f"http://127.0.0.1:{port}"
    server = subprocess.Popen([php,"-S",f"127.0.0.1:{port}","-t","public","public/index.php"],env=env,
                              stdout=subprocess.DEVNULL,stderr=open(Path(temp)/"http.log","w"))
    def call(path, data=None, status=200):
        body = None if data is None else parse.urlencode(data).encode()
        try:
            response = client.open(origin+path, data=body)
        except error.HTTPError as exc:
            response = exc
        with response:
            assert response.status == status, (path, response.status, status)
            return response.read().decode()
    def field(page, name):
        return re.search(r'name="'+name+r'"[^>]*value="([^"]+)"',page)[1]
    try:
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1",port),timeout=.2): break
            except OSError: time.sleep(.05)
        for role in ["viewer","cashier"]:
            client = request.build_opener(request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            login = call("/branches/1")
            assert "Staff sign in" in login
            creds = {"email":role+"@example.test","password":"wrong","_token":field(login,"_token")}
            assert "Unable to sign in" in call("/login",creds)
            creds["password"] = password
            assert "Your branches" in call("/login",creds)
            call("/branches/2",status=404)
            page = call("/branches/1")
            form = {"source":"1","destination":"2","amount":"40.05","_token":field(page,"_token")}
            if role == "viewer":
                assert "Record a transfer" not in page
                call("/branches/1/transfers",form,403)
            else:
                form["request_key"] = field(page,"request_key")
                call("/branches/1/transfers",{**form,"_token":"invalid"},419)
                for _ in range(2):
                    assert "Transfer #1 recorded." in call("/branches/1/transfers",form)
                assert conn.execute("SELECT actor,amount_minor FROM treasury_transfers").fetchall() == [("staff:2",4005)]
                conn.execute("DELETE FROM branch_staff WHERE user_id=2")
                conn.commit()
                call("/branches/1/transfers",form,404)
            assert "Staff sign in" in call("/logout",{"_token":field(page,"_token")})
            assert "Staff sign in" in call("/branches/1")
        print("PASS staff login, logout, branch isolation, viewer restrictions, cashier posting, CSRF, retry and revocation")
    finally:
        server.terminate()
        server.wait()
