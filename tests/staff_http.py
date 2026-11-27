"""Local integration check. Run after composer install. Requires Python and PHP CLI."""
import json
import uuid
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
    conn = sqlite3.connect(str(db))
    conn.executescript("""

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
    server = subprocess.Popen([php,"-d","log_errors=1","-d","error_log=/dev/stderr","-S",f"127.0.0.1:{port}","-t","public","public/index.php"],env=env,
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
            assert "Staff sign in" not in call("/login",creds)
            assert "Recent sales" in call("/")
            call("/branches/2",status=404)
            page = call("/branches/1")
            form = {"source":"1","destination":"2","amount":"40.05","_token":field(page,"_token")}
            if role == "viewer":
                assert "Record a transfer" not in page and "view-only access" in page
                call("/control",status=403)
                call("/branches/2/office/report",status=404)
                call("/branches/1/office/post",form,403)
                call("/branches/1/transfers",form,403)
            else:
                form["request_key"] = field(page,"request_key")
                assert "Bank (bank)" in page
                expired = call("/branches/1/transfers",{**form,"_token":"invalid"})
                assert "This form expired." in expired and 'value="40.05"' in expired
                assert "Choose two different accounts." in call("/branches/1/transfers",{**form,"destination":"1"})
                assert "Use at most 2 decimal places." in call("/branches/1/transfers",{**form,"amount":"1.234"})
                assert "Too many fractional" not in call("/branches/1/transfers?lang=ar",{**form,"amount":"1.234"})
                call("/branches/1?lang=en")
                for _ in range(2):
                    assert "Transfer #1 recorded." in call("/branches/1/transfers",form)
                assert "Transfer #2 recorded." in call("/branches/1/transfers",{**form,"amount":"1,000.50","request_key":field(page,"request_key")[:-4]+"beef"})
                assert "Transfer #3 recorded." in call("/branches/1/transfers",{**form,"amount":"١٢٫٥","request_key":field(page,"request_key")[:-4]+"cafe"})
                assert conn.execute("SELECT actor,amount_minor FROM treasury_transfers").fetchall() == [("staff:2",4005),("staff:2",100050),("staff:2",1250)]
                call("/ping",status=204)
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
        assert "Recent audit events" not in control and "Leave register" not in control
        for category, label in [("Company and accounting settings","Recent audit events"),("Staff and permissions","Leave register")]:
            assert label in call("/control?"+parse.urlencode({"category":category}))
        dashboard = call("/")
        assert "Recent sales" in dashboard and "Journal entries" not in dashboard
        menu = call("/branches/1/office/post")
        daily = menu.split('<div class="task-links daily">',1)[1].split("</div>",1)[0]
        assert daily.count("<a ") == 4 and "task=sale" in daily and "task=transfer" in daily
        assert "task=reverse" in menu and "task=reverse" not in daily
        assert "Journal entries" in call("/branches/1/office/report")
        for section in ["dashboard","report","post","export","statements"]:
            call("/branches/2/office/"+section)
        call("/control", {"_token":token,"action":"party","branch":1,"kind":"customer","name":"Client A","currency":"USD"})
        for setup in ["cash","currency","rate","reconcile","party","credit limit","supplier limit",
                      "supplier reconciliation","installment","user","access","salary terms","leave",
                      "leave decision","company profile","company name","service category","numbering","close period"]:
            query = parse.urlencode({"setup":setup})
            assert 'name="action" value="'+setup+'"' in call("/control?"+query)
        assert "Check day." in call("/control?setup=rate", dict(_token=token, action="rate",
                                   branch=1, currency="SDG", rate="2500"))
        assert "Register supplier" in control
        supplier_form = call("/control?setup=party&kind=supplier")
        assert re.search(r'<option value="supplier" selected', supplier_form)
        directory = call("/control")
        assert re.search(r'href="[^"]*account=customer(?:%3A|:)1[^"]*">Client A</a>', directory)
        invalid_rate = call("/control?setup=rate", dict(_token=token, action="rate", branch=1,
                            currency="USD", day="2026-01-01", rate="2500"))
        assert "The reporting currency always uses a rate of 1." in invalid_rate
        assert conn.execute("SELECT COUNT(*) FROM rates WHERE currency='USD'").fetchone()==(0,)
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
        draft_key=str(uuid.uuid4())
        unfinished=dict(kind="sale",customer="1",currency="USD",day="2026-11-27",
                        amount="576",cost="500",cost_currency="USD",reference="00",request_key=draft_key)
        conn.execute("INSERT INTO requests(branch_id,requested_by,request_key,payload) VALUES (1,2,?,?)",
                     (draft_key,json.dumps(unfinished)))
        conn.commit()
        assert "Continue entry" in call("/")
        assert 'value="576"' in call("/branches/1/office/post?draft=1")
        assert "Select a supplier for the cost" in call("/branches/1/office/post",{**unfinished,"_token":token,"mode":"save"})
        assert conn.execute("SELECT state FROM requests").fetchone()==("pending",)
        corrected={**unfinished,"_token":token,"mode":"save","supplier":"new","new_supplier":"Supplier One"}
        for _ in range(2):
            assert "Saved to the ledger." in call("/branches/1/office/post",corrected)
        doc=conn.execute("SELECT id FROM documents WHERE request_key=?",(draft_key,)).fetchone()[0]
        assert conn.execute("SELECT state,document_id FROM requests").fetchone()==("posted",doc)
        assert conn.execute("SELECT SUM(base_minor) FROM entries WHERE document_id=?",(doc,)).fetchone()==(0,)
        assert conn.execute("SELECT -SUM(base_minor) FROM entries WHERE account IN ('sales','service_cost') AND document_id=?",(doc,)).fetchone()==(7600,)
        assert "76.00" in call("/")
        statement_url="/branches/1/office/report?view=statement&account=customer:1&from=2026-11-01&to=2026-11-30"
        customer_copy=call(statement_url)
        assert "Closing balance" in customer_copy and "586.25" in customer_copy
        assert "Supplier One" not in customer_copy.split('<article class="statement-paper">',1)[1].split('</article>',1)[0]
        assert "Closing balance" in call(statement_url+"&download=csv")
        call(statement_url,dict(_token=token,external="580",side="Dr"))
        assert conn.execute("SELECT external_minor-ledger_minor FROM statement_checks").fetchone()==(-625,)
        call(statement_url.replace("customer:1","customer:999999"),status=404)
        call("/control",dict(_token=token,action="currency",branch=1,code="GBP",precision="2",initial_rate="2000"))
        assert conn.execute("SELECT rate FROM rates WHERE currency='GBP'").fetchone()==("2000",)
        call("/control",dict(_token=token,action="user",name="New staff",email="new@example.test",password=password,branch=1,role="cashier",salary_currency="USD"))
        assert conn.execute("SELECT p.name FROM employee_logins l JOIN parties p ON p.id=l.employee_id").fetchone()==("New staff",)
        call("/control",dict(_token=token,action="numbering",branch=1,prefix="MT",next="100"))
        entry("fee",cash="1",amount="1")
        assert conn.execute("SELECT label FROM document_numbers").fetchone()==("MT-000100",)
        call("/branches/1/office/statements?group=customer&from=2026-01-01&to=2026-12-31")
        entry("sale",customer="1",amount="10",passenger="محمد أحمد",route="KRT/JED")
        travel=conn.execute("SELECT MAX(id) FROM documents WHERE kind='sale'").fetchone()[0]
        for term in ["محمد","KRT/JED"]:
            assert f"D-{travel}" in call("/branches/1/office/report?search="+parse.quote(term))
        call("/control",dict(_token=token,action="rate",branch=1,currency="GBP",rate="٢٬٥٠٠",day="2026-02-01"))
        assert conn.execute("SELECT rate FROM rates WHERE currency='GBP' AND effective='2026-02-01'").fetchone()==("2500",)
        report=call("/branches/1/office/statements?from=2026-01-01&to=2026-12-31")
        assert "Total assets" in report and "Aging of open balances" in report and "Unbalanced difference" not in report
        assert "Outstanding" in call("/branches/1/office/post?task=receipt")
        assert "Sales this month" in call("/branches/1/office/dashboard")
        print("PASS allocations, cancellations, management report, recovered sale, registrations, workbook and document numbering")
        ar = call("/branches/1/office/dashboard?lang=ar")
        assert 'lang="ar" dir="rtl"' in ar and 'تسجيل عملية بيع' in ar
        assert 'تحصيل دفعة من عميل' in call("/branches/1/office/dashboard")
        form_ar = call("/branches/1/office/post?task=sale")
        assert 'سعر البيع' in form_ar and 'name="kind" value="sale"' in form_ar
        assert 'لوحة الإدارة' in call("/control")
        assert 'القيمة' in call("/branches/1/office/statements")
        assert 'lang="en" dir="ltr"' in call("/?lang=en")
        print("PASS language switch, persistence, RTL reports and stable transaction codes")
        # Correct the posted fee, then void its replacement. Failed retries leave one audit event.
        fee=conn.execute("SELECT id FROM documents WHERE kind='fee'").fetchone()[0]
        endpoint="/branches/1/office/post"
        assert 'name="amend"' in call(endpoint+"?amend="+str(fee))
        change=dict(_token=token,amend=fee,mode="save",day="2026-01-01",reason="Test typo",cash=1,amount="2")
        call(endpoint,{**change,"amount":"invalid"})
        assert not conn.execute("SELECT id FROM documents WHERE reversal_of=?",(fee,)).fetchone()
        call(endpoint,change)
        call(endpoint,change)
        assert conn.execute("SELECT count(*) FROM audit_events WHERE action='Amend document'").fetchone()==(1,)
        amended=conn.execute("SELECT max(id) FROM documents WHERE kind='fee'").fetchone()[0]
        call(endpoint,dict(_token=token,amend=amended,mode="void",reason="Duplicate fee"))
        assert conn.execute("SELECT sum(base_minor) FROM ledger WHERE account='expense'").fetchone()==(0,)
        conn.execute("INSERT INTO documents(branch_id,user_id,kind,day,due,reference,details,request_key,fingerprint) VALUES(1,2,'legacy_ticket_cost','2026-01-02','2026-01-02','ARS','{}','import-1','test')")
        legacy=conn.execute("SELECT last_insert_rowid()").fetchone()[0]
        conn.executemany("INSERT INTO entries(document_id,account,currency,native_minor,base_minor,rate) VALUES(?,?,'USD',?,?,'1')",[(legacy,'service_cost',500,500),(legacy,'supplier:2',-500,-500)])
        conn.commit()
        assert 'value="5.00"' in call("/branches/1/office/report?doc="+str(legacy))
        call(endpoint,dict(_token=token,amend=legacy,mode="save",reason="Supplier correction",reference="ARS corrected",ticket="00123",amount="6.00"))
        assert conn.execute("SELECT native_minor FROM entries JOIN documents ON documents.id=document_id WHERE reference='ARS corrected' AND account='supplier:2'").fetchone()==(-600,)
        call("/control",dict(_token=token,action="party",branch=1,kind="customer",name="Typoo",currency="USD"))
        party=conn.execute("SELECT id FROM parties WHERE name='Typoo'").fetchone()[0]
        edit=dict(_token=token,directory="party",record=party,reason="Typo",name="Corrected",detail="Contact")
        call("/control",dict(edit,action="Edit account"))
        call("/control",dict(edit,action="Delete unused account"))
        assert not conn.execute("SELECT id FROM parties WHERE id=?",(party,)).fetchone()
        call("/control",dict(edit,action="Delete unused account",record=1))
        assert conn.execute("SELECT id FROM parties WHERE id=1").fetchone()
        print("PASS amendments, failed correction rollback, repeat submission, legacy amount, void, directory deletion and audit")
        print("PASS administrator access, setup, posting preview, confirmation, report and CSV")
        print("PASS staff login, logout, branch isolation, viewer restrictions, cashier posting, CSRF, retry and revocation")
        post = call("/branches/1/office/post?task=sale")
        sale = {"_token":token,"request_key":field(post,"request_key"),"day":"2026-01-01","kind":"sale",
                "reference":"Comma sale","customer":1,"amount":"1,234.50","currency":"USD","mode":"preview"}
        assert "Confirm posting" in call("/branches/1/office/post",sale)
        assert "Use at most 2 decimal places." in call("/branches/1/office/post",{**sale,"amount":"١٫٢٣٤"})
        assert "Confirm posting" in call("/branches/1/office/post",{**sale,"amount":"١٬٢٣٤٫٥"})
        expired = client.open(request.Request(origin+"/branches/1/office/post",data=parse.urlencode({**sale,"_token":"bad"}).encode(),
                                              headers={"Referer":origin+"/branches/1/office/post?task=sale"}))
        assert expired.status == 200 and "This form expired." in expired.read().decode() and "task=sale" in expired.url
        client = request.build_opener(request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        page = call("/login")
        for _ in range(8):
            page = call("/login", {"email":"viewer@example.test","password":"wrong","_token":field(page,"_token")})
        page = call("/login", {"email":"viewer@example.test","password":password,"_token":field(page,"_token")})
        assert "Too many sign-in attempts" in page and "Unable to sign in" not in page
        print("PASS thousands separators, Arabic digits, decimal limits, expired forms, keepalive and sign-in lockout")
    finally:
        server.terminate()
        server.wait()
