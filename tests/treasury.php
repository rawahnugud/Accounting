<?php
declare(strict_types=1);
require __DIR__.'/../app/Accounting/Treasury.php';
use App\Accounting\Treasury;

$db = new PDO('sqlite::memory:');
$service = new Treasury($db);
$db->exec(file_get_contents(__DIR__.'/../database/001_treasury.sql'));
$db->exec("INSERT INTO currencies VALUES ('USD',2),('SDG',2);
INSERT INTO companies VALUES (1,'Example A','USD'),(2,'Example B','USD');
INSERT INTO branches VALUES (1,1,'Main'),(2,1,'East'),(3,2,'Main');
INSERT INTO cash_accounts VALUES
(1,1,1,'Bank','bank','USD'),(2,1,1,'Safe','safe','USD'),
(3,1,1,'Safe','safe','SDG'),(4,1,2,'Safe','safe','USD'),(5,2,3,'Bank','bank','USD')");
function check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}
function rejects(callable $action, string $label): void {
    try { $action(); }
    catch (InvalidArgumentException|DomainException|PDOException $e) { check(true,$label); return; }
    throw new RuntimeException($label);
}
$post = fn($amount='40.05',$to=2,$key='request-1') =>
    $service->transfer(1,1,1,$to,'USD',$amount,$key,'tester');
$id = $post();
check($post('040.05') === $id, 'retry returns original transfer');
rejects(fn() => $post('50.00'), 'conflicting retry rejected');
foreach (['3.141','0','-4','1e2','92233720368547758.08'] as $amount) {
    rejects(fn() => $post($amount,2,'invalid'), "invalid amount $amount");
}
foreach ([1=>'same account',3=>'currency mismatch',4=>'cross-branch',5=>'cross-company'] as $to=>$label) {
    rejects(fn() => $post('1',$to,'boundary'), $label);
}
rejects(fn() => $db->exec("UPDATE treasury_transfers SET amount_minor=2"), 'posted amount locked');
rejects(fn() => $db->exec('DELETE FROM treasury_transfers'), 'posted transfer retained');
rejects(fn() => $db->exec("UPDATE currencies SET decimals=3 WHERE code='USD'"), 'historical precision locked');
$lines = $db->query('SELECT account_id, debit_minor-credit_minor FROM treasury_journal ORDER BY account_id')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
check($lines === [1=>-4005,2=>4005], 'bank and safe reconcile to the cent');
check((int)$db->query('SELECT COUNT(*) FROM treasury_transfers')->fetchColumn() === 1,'only one transfer posted');
