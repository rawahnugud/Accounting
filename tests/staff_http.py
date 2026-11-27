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
            assert response.status == status, (path, response.status, status, (Path(temp)/"http.log").read_text()[-3500:])
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
                call("/control",status=403)
                call("/branches/2/office/report",status=404)
                call("/branches/1/office/post",form,403)
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
        conn.execute("UPDATE users SET is_admin=1 WHERE id=2")
        conn.commit()
        client = request.build_opener(request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        page = call("/login")
        call("/login", {"email":"cashier@example.test","password":password,"_token":field(page,"_token")})
        control = call("/control")
        token = field(control,"_token")
        assert "Admin control panel" in control
        for section in ["dashboard","report","post","export","statements"]:
            call("/branches/2/office/"+section)
        call("/control", {"_token":token,"action":"party","branch":1,"kind":"customer","name":"Client A","currency":"USD"})
        post = call("/branches/1/office/post?task=sale")
        data = {"_token":token,"request_key":field(post,"request_key"),"day":"2026-01-01","kind":"sale",
                "reference":"Test sale","customer":1,"amount":"15.25","currency":"USD","mode":"preview"}
        assert "Confirm posting" in call("/branches/1/office/post",data)
        assert "Test sale" in call("/branches/1/office/post",{**data,"mode":"edit"})
        assert conn.execute("SELECT COUNT(*) FROM documents").fetchone()[0]==0
        data["mode"]="confirm"
        assert "Document D-1" in call("/branches/1/office/post",data)
        call("/branches/1/office/post",data)
        assert conn.execute("SELECT COUNT(*) FROM documents").fetchone()[0]==1
        assert "15.25" in call("/branches/1/office/dashboard")
        call("/overview")
        call("/branches/1/office/workbook")
        def entry(kind, **values):
            page = call("/branches/1/office/post?task="+kind)
            payload = dict(_token=token,request_key=field(page,"request_key"),day="2026-01-01",
                           reference="Office test",mode="confirm",kind=kind)
            call("/branches/1/office/post",dict(payload,**values))
        entry("receipt",cash="1",customer="1",amount="5")
        call("/branches/1/matches",dict(_token=token,debit=1,credit=2,account="customer:1",amount="5",request_key=field(call("/branches/1/office/post?task=sale"),"request_key")))
        assert conn.execute("SELECT outstanding_minor FROM open_items WHERE id=1").fetchone()==(1025,)
        call("/branches/1/matches",dict(_token=token,remove=1))
        assert conn.execute("SELECT outstanding_minor FROM open_items WHERE id=1").fetchone()==(1525,)
        entry("opening",cash="1",amount="25",mode="request")
        assert conn.execute("SELECT state FROM requests").fetchone()==("pending",)
        call("/branches/1/requests/1",dict(_token=token,state="approved"))
        assert conn.execute("SELECT state FROM requests").fetchone()==("approved",)
        call("/control",dict(_token=token,action="numbering",branch=1,prefix="MT",next="100"))
        entry("fee",cash="1",amount="1")
        assert conn.execute("SELECT label FROM document_numbers").fetchone()==("MT-000100",)
        call("/branches/1/office/statements?group=customer&from=2026-01-01&to=2026-12-31")
        print("PASS allocations, cancellations, management report, approval, workbook and document numbering")
        print("PASS administrator access, setup, posting preview, confirmation, report and CSV")
        print("PASS staff login, logout, branch isolation, viewer restrictions, cashier posting, CSRF, retry and revocation")
    finally:
        server.terminate()
        server.wait()
