<?php
declare(strict_types=1);
namespace App\Accounting;

use PDO;
use DomainException;

final class Books
{
    public const ADMIN_TYPES = ['opening','opening_party','reverse','deposit','withdrawal','payroll','advance','recovery','salary_payment','liability','loan'];
    public const EXPENSE_CATEGORIES = ['Rent','Utilities','Staff costs','Visa and government fees','Bank charges','Office supplies','Marketing','Transport','Communication','Maintenance'];
    public const TYPES = [
        'sale'=>'Service sale', 'reissue'=>'Ticket reissue (incremental amounts)', 'refund'=>'Customer and supplier credit',
        'receipt'=>'Customer receipt', 'supplier_payment'=>'Supplier payment', 'customer_refund'=>'Pay customer refund',
        'supplier_refund'=>'Receive supplier refund', 'liability'=>'Record creditor obligation', 'loan'=>'Receive a creditor loan', 'repayment'=>'Repay creditor',
        'payroll'=>'Accrue salary', 'salary_payment'=>'Pay salary', 'advance'=>'Employee advance', 'recovery'=>'Recover advance from salary',
        'opening_party'=>'Opening customer or supplier balance', 'opening'=>'Opening cash balance', 'deposit'=>'Owner contribution', 'withdrawal'=>'Owner withdrawal',
        'fee'=>'Bank fee or expense', 'transfer'=>'Bank or safe transfer', 'reverse'=>'Reverse or void document'
    ];
    public function __construct(private PDO $db, private int $branch, private int $actor) {}
    public function rows(string $sql, array $args=[]): array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function one(string $sql, array $args): array
    {
        return $this->rows($sql,$args)[0] ?? throw new DomainException('The selected record is unavailable.');
    }
    public static function date(string $value): string
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d') !== $value) { throw new DomainException('Enter a valid date.'); }
        return $value;
    }
    private array $overrides=[];
    private function rate(string $currency,string $day): array
    {
        $row=$this->one('SELECT c.reporting_currency AS base, n.decimals AS places, r.decimals AS base_places
            FROM branches b JOIN companies c ON c.id=b.company_id JOIN currencies r ON r.code=c.reporting_currency
            JOIN currencies n ON n.code=? WHERE b.id=?',[$currency,$this->branch]);
        // Latest rate on or before the day, preferring this branch over the company's other branches; otherwise the first later rate.
        $rate=$currency===$row['base'] ? '1' : ($this->overrides[$currency] ?? $this->rows('SELECT r.rate FROM rates r JOIN branches b ON b.id=r.branch_id
            JOIN branches me ON me.id=? AND me.company_id=b.company_id WHERE r.currency=?
            ORDER BY r.effective>?, r.branch_id<>me.id, CASE WHEN r.effective<=? THEN r.effective END DESC, r.effective LIMIT 1',
            [$this->branch,$currency,$day,$day])[0]['rate'] ?? throw new DomainException("No exchange rate for $currency yet. An administrator can add one in the control panel under Set exchange rate."));
        return $row+['rate'=>$rate];
    }
    private function convert(int $amount,array $rate,bool $toBase): int
    {
        $scaled=Amounts::parse($rate['rate'],6);
        if (!$scaled || $scaled>1000000000000) { throw new DomainException('Rate must be positive and at most one million.'); }
        $factor=10**(6+$rate['base_places']-$rate['places']);
        return $toBase ? Amounts::ratio($amount,$factor,$scaled) : Amounts::ratio($amount,$scaled,$factor);
    }
    public function transact(array $f, bool $preview=false): array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result=$this->build($f,$preview);
            $this->db->exec($preview ? 'ROLLBACK' : 'COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
    /** Used when an allocation cancellation already owns the SQLite write transaction. */
    public function postWithinTransaction(array $form): array
    {
        return $this->build($form,false);
    }
    private function build(array $f,bool $preview): array
    {
        $access=$this->one("SELECT u.is_admin, s.role FROM users u LEFT JOIN branch_staff s ON s.user_id=u.id AND s.branch_id=? WHERE u.id=?",[$this->branch,$this->actor]);
        $admin=(bool)$access['is_admin'];
        if (!$admin && $access['role']!=='cashier') { throw new DomainException('Posting permission required.'); }
        $kind=$f['kind'] ?? '';
        if (!isset(self::TYPES[$kind])) { throw new DomainException('Choose an operation.'); }
        if (!$admin && in_array($kind,self::ADMIN_TYPES,true)) {
            throw new DomainException('Administrator access required.');
        }
        $day=self::date($f['day'] ?? '');
        $due=self::date(($f['due'] ?? '') ?: $day);
        $ref=trim($f['reference'] ?? '');
        $key=$f['request_key'] ?? '';
        if ($ref==='') { $ref='MT-'.strtoupper(substr($key,0,8)); }
        if (strlen($ref)>160 || !preg_match('/\A[a-f\d-]{36}\z/i',$key)) { throw new DomainException('Enter a reference and reopen the form if it expired.'); }
        unset($f['_token'],$f['mode']); ksort($f);
        $fingerprint=hash('sha256',json_encode([$this->actor,$f],JSON_THROW_ON_ERROR));
        $old=$this->rows('SELECT id,fingerprint FROM documents WHERE branch_id=? AND request_key=?',[$this->branch,$key]);
        if ($old) {
            if ($old[0]['fingerprint']!==$fingerprint) { throw new DomainException('Request already used with different details.'); }
            return ['id'=>$old[0]['id'],'lines'=>[]];
        }
        $draft=$this->rows('SELECT id,state,requested_by FROM requests WHERE branch_id=? AND request_key=?',[$this->branch,$key])[0] ?? null;
        if ($draft && ($draft['state']!=='pending' || (!$admin && (int)$draft['requested_by']!==$this->actor))) {
            throw new DomainException('This unfinished entry is no longer available.');
        }
        $closed=$this->rows('SELECT closed_through FROM periods WHERE branch_id=?',[$this->branch]);
        if ($closed && $day<=$closed[0]['closed_through']) { throw new DomainException('This period is closed.'); }
        $branch=$this->one('SELECT c.reporting_currency AS base FROM branches b JOIN companies c ON c.id=b.company_id WHERE b.id=?',[$this->branch]);
        $base=$branch['base'];
        $cash=function(string $field) use ($f):array {
            if (empty($f[$field])) { throw new DomainException($field==='destination'?'Choose the destination bank or safe.':'Choose the bank or safe.'); }
            $a=$this->one('SELECT id,currency FROM cash_accounts WHERE branch_id=? AND id=?',[$this->branch,$f[$field]]);
            return ['cash:'.$a['id'],$a['currency']];
        };
        if ($kind==='sale') {
            foreach (['customer'=>'currency','supplier'=>'cost_currency'] as $type=>$currencyField) {
                if (($f[$type] ?? '')!=='new') { continue; }
                $name=trim($f['new_'.$type] ?? '');
                if ($name==='' || strlen($name)>200) { throw new DomainException('Enter the new account name.'); }
                $currency=($f[$currencyField] ?? '') ?: $base;
                $this->one('SELECT code FROM currencies WHERE code=?',[$currency]);
                $this->db->prepare('INSERT INTO parties(branch_id,kind,name,currency) VALUES (?,?,?,?)
                    ON CONFLICT(branch_id,kind,name) DO NOTHING')->execute([$this->branch,$type,$name,$currency]);
                $record=$this->one('SELECT id,currency FROM parties WHERE branch_id=? AND kind=? AND name=?',[$this->branch,$type,$name]);
                if ($record['currency']!==$currency) { throw new DomainException('An account with this name already uses another currency. Select the existing account.'); }
                $f[$type]=(string)$record['id'];
            }
        }
        $party=function(string $type) use ($f):array {
            $a=$this->one('SELECT id,currency FROM parties WHERE branch_id=? AND kind=? AND id=?',[$this->branch,$type,$f[$type] ?? 0]);
            return [$type.':'.$a['id'],$a['currency']];
        };
        $lines=[];
        $line=function(array $account,int $native,int $report,string $rate) use (&$lines):void {
            $lines[]=['account'=>$account[0],'currency'=>$account[1],'native'=>$native,'base'=>$report,'rate'=>$rate];
        };
        $pair=function(array $debit,array $credit,string $input,string $source) use ($day,$line):void {
            $rate=$this->rate($source,$day);
            $minor=Amounts::parse($input,$rate['places']);
            $report=$this->convert($minor,$rate,true);
            if (!$minor || !$report) { throw new DomainException('Amount must round to a positive reporting amount.'); }
            foreach ([[$debit,1],[$credit,-1]] as [$account,$sign]) {
                $target=$this->rate($account[1],$day);
                $native=$account[1]===$source ? $minor : $this->convert($report,$target,false);
                if (!$native) { throw new DomainException('Amount rounds to zero in account currency.'); }
                $line($account,$sign*$native,$sign*$report,$target['rate']);
            }
        };
        $amount=$f['amount'] ?? '';
        $related=(int)($f['related'] ?? 0);
        $original=$related ? $this->one('SELECT * FROM documents WHERE branch_id=? AND id=?',[$this->branch,$related]) : null;
        if ($related && !in_array($kind,['reverse','reissue','refund','receipt','supplier_payment','salary_payment','repayment','customer_refund','supplier_refund'],true)) {
            throw new DomainException('This transaction cannot link an original document.');
        }
        if ($original && ($day<$original['day'] || $original['reversal_of'] || $this->rows('SELECT id FROM documents WHERE reversal_of=?',[$related]))) {
            throw new DomainException('Choose an active original document dated on or before this operation.');
        }
        $source=($f['currency'] ?? '') ?: $base;
        if ($kind==='opening_party' && !empty($f['customer']) && !empty($f['supplier'])) {
            throw new DomainException('Choose one opening balance account.');
        }
        $costSource=($f['cost_currency'] ?? '') ?: $source;
        $cost=$f['cost'] ?? '';
        $this->overrides=[];
        $override=trim($f['override'] ?? '');
        // The override applies to the operation's foreign currency: the amount currency for sales, otherwise the account side that is not in the reporting currency.
        $applyOverride=function(string $currency) use ($override,$admin,$base):void {
            if ($override==='') { return; }
            if (!$admin || $currency===$base || !Amounts::parse($override,6) || Amounts::parse($override,6)>1000000000000) {
                throw new DomainException('An administrator may override a foreign currency rate within the supported range.');
            }
            $this->overrides[$currency]=Amounts::clean($override);
        };
        if (in_array($kind,['sale','reissue','refund'],true)) { $applyOverride($source); }
        $converted=trim($f['converted'] ?? '');
        if ($converted!=='' && in_array($kind,['sale','reissue','refund'],true)) {
            $r=$this->rate($source,$day);
            $baseAmount=Amounts::parse($converted,$r['base_places']);
            if ($amount==='') {
                $amount=Amounts::format($this->convert($baseAmount,$r,false),$r['places']);
            } elseif ($source!==$base && $override==='' && $admin && $baseAmount>0) {
                $scaled=Amounts::ratio(Amounts::parse($amount,$r['places']),10**(6+$r['base_places']-$r['places']),$baseAmount);
                $this->overrides[$source]=Amounts::format($scaled,6);
            }
            if ($this->convert(Amounts::parse($amount,$r['places']),$this->rate($source,$day),true)!==$baseAmount) {
                throw new DomainException('Converted amount and exchange rate disagree.');
            }
        }
        $meta=['override_by'=>$this->overrides?$this->actor:null];

        $category=trim($f['category'] ?? '');
        if ($category!=='' && (!in_array($kind,['fee','liability'],true) || !in_array($category,self::EXPENSE_CATEGORIES,true))) {
            throw new DomainException('Choose an expense category from the list.');
        }
        $inherited=$original && in_array($kind,['reissue','refund'],true) ? json_decode($original['details'],true) : [];
        foreach (['service','passenger','pnr','ticket','airline','route','note','category'] as $field) {
            $meta[$field]=trim($f[$field] ?? '');
            // Reissues and refunds keep the original ticket's details unless staff enter new ones.
            if ($meta[$field]==='' && !in_array($field,['note','category'],true)) { $meta[$field]=(string)($inherited[$field] ?? ''); }
            if (strlen($meta[$field])>500) { throw new DomainException('Detail exceeds 500 characters.'); }
        }
        if ($kind==='payroll') {
            $r=$this->rate($source,$day);
            $allowance=Amounts::parse(($f['allowance'] ?? '') ?: '0',$r['places']);
            $deduction=Amounts::parse(($f['deduction'] ?? '') ?: '0',$r['places']);
            if ($amount==='') {
                $terms=$this->one("SELECT t.salary_minor,p.currency FROM employee_terms t JOIN parties p ON p.id=t.employee_id WHERE p.branch_id=? AND p.id=?",[$this->branch,$f['employee']]);
                if ($terms['currency']!==$source) { throw new DomainException('Select the salary currency.'); }
                $amount=Amounts::format($terms['salary_minor'],$r['places']);
            }
            $net=Amounts::parse($amount,$r['places'])+$allowance-$deduction;
            if ($net<=0) { throw new DomainException('Net pay must be positive.'); }
            $meta+=['salary'=>$amount,'allowance'=>$f['allowance'] ?? '','deduction'=>$f['deduction'] ?? ''];
            $amount=Amounts::format($net,$r['places']);
        }
        if (!empty($f['employee']) && $kind==='recovery') {
            $meta['employee_id']=$f['employee'];
        }
        $meta+=['amount'=>$amount,'source'=>$source,'cost'=>$cost,'cost_currency'=>$costSource];
        if (in_array($kind,['sale','reissue','refund'],true)) {
            $client=$party('customer');
            if ($kind!=='sale' && (!$original || !in_array($original['kind'],['sale','reissue'],true) ||
                !$this->rows('SELECT id FROM entries WHERE document_id=? AND account=?',[$related,$client[0]]))) {
                throw new DomainException('Choose the original sale for this customer.');
            }
            if ($kind==='sale' && $original) { throw new DomainException('Use reissue or refund to link an original ticket.'); }
            $income=['sales',$base]; $expense=['service_cost',$base];
            $credit=$kind==='refund';
            $pair($credit?$income:$client,$credit?$client:$income,$amount,$source);
            $meta['source_rate']=$this->rate($source,$day)['rate'];
            if ($cost!=='' && Amounts::parse($cost,$this->rate($costSource,$day)['places'])>0) {
                if (empty($f['supplier'])) { throw new DomainException('Select a supplier for the cost, or add a new supplier.'); }
                $supplier=$party('supplier');
                $pair($credit?$supplier:$expense,$credit?$expense:$supplier,$cost,$costSource);
                $meta['cost_rate']=$this->rate($costSource,$day)['rate'];
            }
            if ($kind==='refund') {
                foreach ($lines as $entry) {
                    if (!str_contains($entry['account'],':')) { continue; }
                    $open=$this->one('SELECT original_minor FROM open_items WHERE id=? AND account=?',[$related,$entry['account']]);
                    $credited=$this->rows("SELECT COALESCE(SUM(e.native_minor),0) AS prior FROM entries e JOIN documents d ON d.id=e.document_id
                        WHERE d.related_id=? AND d.kind='refund' AND e.account=? AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id)",[$related,$entry['account']])[0]['prior'];
                    if (abs($credited+$entry['native'])>abs($open['original_minor'])) { throw new DomainException('Credit exceeds the original amount.'); }
                }
            }
        } elseif ($kind==='reverse') {
            if ($this->rows('SELECT id FROM matches WHERE debit_doc=? OR credit_doc=? OR fx_doc=?',[$related,$related,$related])) {
                throw new DomainException('Remove the allocation before reversing a matched document.');
            }
            if (!$original || $this->rows('SELECT d.id FROM documents d WHERE d.related_id=? AND NOT EXISTS (SELECT 1 FROM documents x WHERE x.reversal_of=d.id)',[$related])) {
                throw new DomainException('Reverse linked payments or credits before the original document.');
            }
            foreach ($this->rows('SELECT * FROM entries WHERE document_id=?',[$related]) as $e) {
                $line([$e['account'],$e['currency']],-$e['native_minor'],-$e['base_minor'],$e['rate']);
            }
        } else {
            $accounts=match($kind) {
                'receipt'=>[$cash('cash'),$party('customer')], 'customer_refund'=>[$party('customer'),$cash('cash')],
                'supplier_payment'=>[$party('supplier'),$cash('cash')], 'supplier_refund'=>[$cash('cash'),$party('supplier')],
                'liability'=>[['expense',$base],$party('creditor')], 'loan'=>[$cash('cash'),$party('creditor')], 'repayment'=>[$party('creditor'),$cash('cash')],
                'payroll'=>[['salary_expense',$base],$party('employee')], 'salary_payment'=>[$party('employee'),$cash('cash')],
                'advance'=>[['advance:'.explode(':',$party('employee')[0])[1],$party('employee')[1]],$cash('cash')],
                'recovery'=>[$party('employee'),['advance:'.explode(':',$party('employee')[0])[1],$party('employee')[1]]],
                'opening_party'=>($f['customer'] ?? '')!=='' ? [$party('customer'),['equity',$base]] : [['equity',$base],$party('supplier')],
                'opening','deposit'=>[$cash('cash'),['equity',$base]], 'withdrawal'=>[['equity',$base],$cash('cash')],
                'fee'=>[['expense',$base],$cash('cash')], 'transfer'=>[$cash('destination'),$cash('cash')]
            };
            if ($accounts[0]===$accounts[1]) { throw new DomainException('Choose two different accounts.'); }
            foreach ($accounts as [$code,$currency]) { if (str_starts_with($code,'cash:')) { $source=$currency; } }
            if ($kind==='transfer') { $source=$accounts[1][1]; }
            $applyOverride($source!==$base ? $source : ($accounts[0][1]!==$base ? $accounts[0][1] : $accounts[1][1]));
            $pair($accounts[0],$accounts[1],$amount,$source);
            $meta['source']=$source;
            $meta['source_rate']=$this->rate($source,$day)['rate'];
            if ($original) {
                $map=['receipt'=>'customer','supplier_payment'=>'supplier','salary_payment'=>'employee','repayment'=>'creditor',
                    'customer_refund'=>'customer','supplier_refund'=>'supplier'];
                if (!isset($map[$kind])) { throw new DomainException('This operation cannot allocate to another document.'); }
                $account=$party($map[$kind])[0];
                $validKinds=match($kind) {
                    'receipt','supplier_payment'=>['sale','reissue','opening_party'],
                    'salary_payment'=>['payroll'], 'repayment'=>['liability','loan'],
                    'customer_refund','supplier_refund'=>['refund']
                };
                if (!in_array($original['kind'],$validKinds,true)) { throw new DomainException('Choose a matching invoice or obligation.'); }
                $matched=$this->rows('SELECT id FROM matches WHERE account=? AND (debit_doc=? OR credit_doc=?)',[$account,$related,$related]);
                if ($matched) { throw new DomainException('Post this payment on account and use Match open items for the remaining balance.'); }
                $out=$this->one("SELECT SUM(e.native_minor) AS native,SUM(e.base_minor) AS base FROM entries e JOIN documents d ON d.id=e.document_id
                    WHERE e.account=? AND (d.id=? OR (d.related_id=? AND d.kind IN ('receipt','supplier_payment','salary_payment','repayment','customer_refund','supplier_refund') AND NOT EXISTS (SELECT 1 FROM documents x WHERE x.reversal_of=d.id)))",[$account,$related,$related]);
                if (!$out['native']) { throw new DomainException('Choose an open invoice for this account.'); }
                foreach ($lines as &$entry) {
                    if ($entry['account']!==$account) { continue; }
                    if (!$out['native'] || ($out['native']>0)===($entry['native']>0) || abs($entry['native'])>abs($out['native'])) {
                        throw new DomainException('Allocation exceeds the outstanding document balance.');
                    }
                    $carrying=Amounts::ratio(abs($out['base']),abs($entry['native']),abs($out['native']))*($entry['native']<0?-1:1);
                    $difference=$entry['base']-$carrying;
                    $entry['base']=$carrying;
                    if ($difference) { $line(['fx',$base],$difference,$difference,'1'); }
                    break;
                }
                unset($entry);
            }
        }
        if ($kind==='sale' || $kind==='reissue') {
            $partyId=explode(':',$client[0])[1];
            $limit=$this->rows('SELECT limit_minor FROM credit_limits WHERE party_id=?',[$partyId]);
            $balance=$this->rows('SELECT COALESCE(SUM(native_minor),0) AS balance FROM ledger WHERE branch_id=? AND account=?',[$this->branch,$client[0]])[0]['balance'];
            if ($limit && $balance+$lines[0]['native']>$limit[0]['limit_minor']) { throw new DomainException('Customer credit limit exceeded.'); }
        }
        if (in_array($kind,['sale','reissue'],true) && isset($supplier)) {
            $limit=$this->rows('SELECT limit_minor FROM credit_limits WHERE party_id=?',[explode(':',$supplier[0])[1]]);
            $owed=$this->rows('SELECT -COALESCE(SUM(native_minor),0) AS owed FROM ledger WHERE branch_id=? AND account=?',[$this->branch,$supplier[0]])[0]['owed'];
            if ($limit && $owed-$lines[3]['native']>$limit[0]['limit_minor']) { throw new DomainException('Supplier credit limit exceeded.'); }
        }
        if ($kind==='recovery') {
            foreach ($lines as $entry) {
                $balance=$this->rows('SELECT COALESCE(SUM(native_minor),0) AS amount FROM ledger WHERE branch_id=? AND account=?',[$this->branch,$entry['account']])[0]['amount'];
                if (($entry['native']>0 && -$balance<$entry['native']) || ($entry['native']<0 && $balance<-$entry['native'])) {
                    throw new DomainException('Recovery exceeds accrued salary or outstanding advances.');
                }
            }
        }
        if ($kind==='recovery') {
            // Relieve the advance at its carrying value so a fully recovered advance leaves no reporting-currency balance.
            foreach ($lines as &$entry) {
                if (!str_starts_with($entry['account'],'advance:')) { continue; }
                $held=$this->one('SELECT COALESCE(SUM(native_minor),0) AS native,COALESCE(SUM(base_minor),0) AS base FROM ledger WHERE branch_id=? AND account=?',[$this->branch,$entry['account']]);
                if ($held['native']>0 && $held['base']>0) {
                    $carrying=-Amounts::ratio((int)$held['base'],-$entry['native'],(int)$held['native']);
                    $difference=$entry['base']-$carrying;
                    $entry['base']=$carrying;
                    if ($difference) { $line(['fx',$base],$difference,$difference,'1'); }
                }
                break;
            }
            unset($entry);
        }
        $meta['override_by']=$this->overrides?$this->actor:null;
        if ($this->overrides && $override!=='') { $meta['override']=Amounts::clean($override); }
        if (!$lines || array_sum(array_column($lines,'base'))!==0) { throw new DomainException('Unbalanced document.'); }
        if ($preview) { return ['id'=>null,'lines'=>$lines]; }
        $this->db->prepare('INSERT INTO documents(branch_id,user_id,kind,day,due,reference,details,related_id,reversal_of,request_key,fingerprint) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$this->branch,$this->actor,$kind,$day,$due,$ref,json_encode($meta,JSON_THROW_ON_ERROR),$related?:null,$kind==='reverse'?$related:null,$key,$fingerprint]);
        $id=(int)$this->db->lastInsertId();
        $insert=$this->db->prepare('INSERT INTO entries(document_id,account,currency,native_minor,base_minor,rate) VALUES (?,?,?,?,?,?)');
        foreach ($lines as $e) { $insert->execute([$id,...array_values($e)]); }
        $auditDetail="D-$id $kind";
        if ($kind==='reverse') { $auditDetail.=" · D-$related · ".$meta['note']; }
        $this->db->prepare("INSERT INTO audit_events(user_id,action,detail) VALUES (?,'post',?)")->execute([$this->actor,$auditDetail]);
        if ($draft) {
            $this->db->prepare("UPDATE requests SET state='posted', decision_by=?, document_id=? WHERE id=? AND state='pending'")
                ->execute([$this->actor, $id, $draft['id']]);
        }
        return ['id'=>$id,'lines'=>$lines];
    }
}
