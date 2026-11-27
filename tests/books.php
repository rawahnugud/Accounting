<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Accounting\Books;
use Symfony\Component\Uid\Uuid;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
foreach (glob(dirname(__DIR__).'/database/*.sql') as $f) { $db->exec(file_get_contents($f)); }
$db->exec("INSERT INTO users(id,name,email,password,is_admin) VALUES (1,'Admin','local@test.invalid','unused',1),(2,'Viewer','reader@test.invalid','unused',0);

INSERT INTO companies VALUES (1,'Test','USD'); INSERT INTO branches VALUES (1,1,'Sudan'),(2,1,'Other');
INSERT INTO cash_accounts VALUES (1,1,1,'USD bank','bank','USD'),(2,1,1,'SDG safe','safe','SDG');
INSERT INTO parties VALUES (1,1,'customer','C','USD',''),(2,1,'supplier','S','USD',''),(3,1,'employee','E','USD',''),(4,1,'creditor','L','USD','');
INSERT INTO rates VALUES (1,'SDG','2026-01-01','3000'),(1,'SDG','2026-02-01','4000');");
$book=new Books($db,1,1);
function form(string $kind,array $v=[]): array {
    return $v+['kind'=>$kind,'request_key'=>(string)Uuid::v4(),'reference'=>'TEST','day'=>'2026-01-05','currency'=>'USD'];
}
function same($actual,$want): void { if ($actual!==$want) { throw new RuntimeException(json_encode([$actual,$want])); } }
function denied(callable $fn):void { try { $fn(); } catch (DomainException $e) { return; } throw new RuntimeException('Expected rejection'); }
$post=fn($k,$v)=>$book->transact(form($k,$v))['id'];
$balance=fn($a)=>(int)$db->query('SELECT COALESCE(SUM(native_minor),0) FROM ledger WHERE account='.$db->quote($a))->fetchColumn();
$sale=form('sale',['customer'=>1,'supplier'=>2,'amount'=>'3000000','currency'=>'SDG','cost'=>'1000','cost_currency'=>'USD']);
$book->transact($sale,true);
same($balance('customer:1'),0);
$id=$book->transact($sale)['id'];
same($book->transact($sale)['id'],$id);
same($balance('customer:1'),100000); same($balance('supplier:2'),-100000);
$post('receipt',['cash'=>1,'customer'=>1,'amount'=>'400','related'=>$id]);
same($balance('customer:1'),60000); same($balance('cash:1'),40000);
$post('supplier_payment',['cash'=>2,'supplier'=>2,'amount'=>'3000000','related'=>$id]);
same($balance('supplier:2'),0); same($balance('cash:2'),-300000000);
$post('transfer',['cash'=>1,'destination'=>2,'amount'=>'100']);
same($balance('sales'),-100000);
$fx=$post('receipt',['cash'=>2,'customer'=>1,'amount'=>'2400000','related'=>$id,'day'=>'2026-02-10']);
same($balance('customer:1'),0);
$post('reverse',['related'=>$fx,'day'=>'2026-02-11']);
same($balance('customer:1'),60000);
denied(fn()=>$post('reverse',['related'=>$id]));
denied(fn()=>$post('receipt',['customer'=>1,'cash'=>1,'amount'=>'601','related'=>$id]));
$liability=$post('liability',['creditor'=>4,'amount'=>'20000']);
$post('repayment',['creditor'=>4,'cash'=>1,'amount'=>'250','related'=>$liability]);
same($balance('creditor:4'),-1975000);
$post('payroll',['employee'=>3,'amount'=>'1000','allowance'=>'50','deduction'=>'25']);
$post('advance',['employee'=>3,'cash'=>1,'amount'=>'100']);
$post('recovery',['employee'=>3,'amount'=>'100']);
same($balance('advance:3'),0); same($balance('employee:3'),-92500);
$reissue=$post('reissue',['customer'=>1,'amount'=>'20','related'=>$id]);
$post('reverse',['related'=>$reissue]);
$credit=$post('refund',['customer'=>1,'amount'=>'20','related'=>$id]);
$post('customer_refund',['customer'=>1,'amount'=>'20','cash'=>1,'related'=>$credit]);
$foreign=$post('sale',['customer'=>1,'currency'=>'SDG','amount'=>'2000','converted'=>'1']);
same($db->query("SELECT SUM(base_minor) FROM entries WHERE document_id=$foreign AND account='sales'")->fetchColumn(),-100);
denied(fn()=>(new Books($db,2,1))->transact(form('receipt',['cash'=>1,'customer'=>1,'amount'=>'1'])));
denied(fn()=>(new Books($db,1,2))->transact($sale));
$draft=form('sale',['customer'=>'1','amount'=>'576','cost'=>'500','currency'=>'USD','cost_currency'=>'USD']);
$db->prepare("INSERT INTO requests(branch_id,requested_by,request_key,payload) VALUES (1,1,?,?)")->execute([$draft['request_key'],json_encode($draft)]);
denied(fn()=>$book->transact($draft));
same($db->query('SELECT state FROM requests')->fetchColumn(),'pending');
$draft['supplier']='new';
$draft['new_supplier']='Synthetic supplier';
$document=$book->transact($draft)['id'];
same($book->transact($draft)['id'],$document);
same((int)$db->query("SELECT document_id FROM requests WHERE state='posted'")->fetchColumn(),$document);
same((int)$db->query("SELECT -SUM(base_minor) FROM entries WHERE document_id=$document AND account IN ('sales','service_cost')")->fetchColumn(),7600);
echo "PASS unfinished USD 576 sale, missing supplier recovery, single posting and USD 76 margin\n";
$statements=new App\Accounting\AccountStatement($db);
$before=$statements->read(1,'customer:1','USD',2,'2026-03-01','2026-03-31');
$expected=(int)$db->query("SELECT COALESCE(SUM(native_minor),0) FROM ledger WHERE branch_id=1 AND account='customer:1' AND day<='2026-03-31'")->fetchColumn();
same($before['closing_minor'],$expected);
same($before['lines'],[]);
$supplier=$statements->read(1,'supplier:2','USD',2,'2026-01-01','2026-01-31');
same($supplier['closing_minor'],0);
same($supplier['debits'],'1000.00'); same($supplier['credits'],'1000.00');
same((int)$db->query("SELECT COUNT(*) FROM currencies WHERE code IN ('USD','SDG','AED','SAR')")->fetchColumn(),4);
echo "PASS statement balances, supplier debit/credit, inactive period and standard currencies\n";
$db->exec("INSERT INTO periods VALUES (1,'2026-12-31')");
denied(fn()=>$post('opening',['cash'=>1,'amount'=>'100']));
same((int)$db->query('SELECT COUNT(*) FROM (SELECT document_id FROM entries GROUP BY document_id HAVING SUM(base_minor)<>0)')->fetchColumn(),0);
echo "PASS posting preview, exact FX, immutable balanced books, allocation, repeat submission, transfer, liability, payroll, ticket history, access and closed period\n";
