<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Accounting\Amounts;
use App\Accounting\AccountStatement;
use App\Language;
use App\Accounting\Books;
use App\Accounting\Reports;
use DomainException;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Uid\Uuid;

final class OfficeController extends AbstractController
{
    public function __construct(private PDO $db, private Language $language) {}

    private function rows(string $sql,array $args=[]): array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function identity(): array
    {
        return $this->rows('SELECT id,is_admin FROM users WHERE email=?',[$this->getUser()->getUserIdentifier()])[0] ?? throw $this->createAccessDeniedException();
    }
    private function csrf(Request $r): void
    {
        if (!$this->isCsrfTokenValid('staff',$r->request->get('_token'))) { throw new HttpException(419,'Form expired.'); }
    }
    private function field(array $f,string $key): string
    {
        $v=trim($f[$key] ?? '');
        if ($v==='' || strlen($v)>200) { throw new DomainException("Check $key."); }
        return $v;
    }
    public function overview(): Response
    {
        if (!$this->identity()['is_admin']) { throw $this->createAccessDeniedException(); }
        $rows=$this->rows("SELECT c.name AS company,b.id,b.name,c.reporting_currency AS currency,v.decimals,
            COALESCE(SUM(CASE WHEN l.account='sales' THEN -l.base_minor ELSE 0 END),0) AS sales,
            COALESCE(SUM(CASE WHEN l.account LIKE 'cash:%' THEN l.base_minor ELSE 0 END),0) AS cash,
            COALESCE(SUM(CASE WHEN l.account IN ('sales','service_cost','expense','salary_expense','fx') THEN -l.base_minor ELSE 0 END),0) AS profit
            FROM branches b JOIN companies c ON c.id=b.company_id JOIN currencies v ON v.code=c.reporting_currency
            LEFT JOIN ledger l ON l.branch_id=b.id GROUP BY b.id ORDER BY c.name,b.name");
        $totals=[];
        foreach ($rows as &$row) {
            $currency=$row['currency'];
            foreach (['sales','cash','profit'] as $col) {
                $totals[$currency][$col]=($totals[$currency][$col] ?? 0)+$row[$col];
                $row[$col]=Amounts::format($row[$col],$row['decimals']);
            }
            $totals[$currency]['precision']=$row['decimals'];
        } unset($row);
        foreach ($totals as &$group) {
            foreach (['sales','cash','profit'] as $col) { $group[$col]=Amounts::format($group[$col],$group['precision']); }
        } unset($group);
        return $this->render('overview.html.twig',compact('rows','totals'));
    }

    public function control(Request $r): Response
    {
        $u=$this->identity();
        if (!$u['is_admin']) { throw $this->createAccessDeniedException(); }
        if ($r->isMethod('POST')) {
            $this->csrf($r);
            $f=$r->request->all();
            if (array_filter($f,'is_array')) { throw new HttpException(422,'Invalid form fields.'); }
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $detail=$this->setup($f,(int)$u['id']);
                $this->db->prepare('INSERT INTO audit_events(user_id,action,detail) VALUES (?,?,?)')->execute([$u['id'],$f['action'],$detail]);
                $this->db->exec('COMMIT');
                $this->addFlash('message','Saved.');
            } catch (\Throwable $e) {
                $this->db->exec('ROLLBACK');
                if (!$e instanceof DomainException && !$e instanceof \PDOException) { throw $e; }
                $this->addFlash('error',$e instanceof DomainException?$e->getMessage():'Check the selected records and duplicate values.');
            }
            $setup=(string)$r->query->get('setup','');
            return $this->redirectToRoute('control',$setup!=='' ? ['setup'=>$setup,'branch'=>(int)($f['branch'] ?? 0),'kind'=>($f['kind'] ?? 'customer')] : []);
        }
        return $this->render('control.html.twig',[
            'branches'=>$this->rows('SELECT b.*,c.name AS company,c.reporting_currency FROM branches b JOIN companies c ON c.id=b.company_id ORDER BY c.name,b.name'),
            'companies'=>$this->rows('SELECT * FROM companies ORDER BY name'),'currencies'=>$this->rows('SELECT * FROM currencies ORDER BY code'),
            'employees'=>$this->rows("SELECT p.*,b.name AS branch,l.user_id AS login_id FROM parties p JOIN branches b ON b.id=p.branch_id LEFT JOIN employee_logins l ON l.employee_id=p.id WHERE kind='employee'"),
            'leave'=>$this->rows('SELECT l.*,p.name FROM staff_leave l JOIN parties p ON p.id=l.employee_id ORDER BY l.id DESC LIMIT 100'),
            'users'=>$this->rows('SELECT id,name,email,is_admin FROM users ORDER BY name'),
            'parties'=>$this->rows('SELECT p.*,b.name AS branch,t.job_title,t.salary_minor,k.limit_minor,c.decimals FROM parties p JOIN branches b ON b.id=p.branch_id JOIN currencies c ON c.code=p.currency LEFT JOIN employee_terms t ON t.employee_id=p.id LEFT JOIN credit_limits k ON k.party_id=p.id ORDER BY b.name,p.name'),
            'profiles'=>$this->rows('SELECT p.*,c.name FROM company_profiles p JOIN companies c ON c.id=p.company_id'),
            'obligations'=>$this->rows("SELECT d.id,d.reference,b.name FROM documents d JOIN branches b ON b.id=d.branch_id WHERE kind='liability' AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id)"),
            'banks'=>$this->rows('SELECT a.*,b.name AS branch FROM cash_accounts a JOIN branches b ON b.id=a.branch_id'),
            'audit'=>$this->rows('SELECT a.*,u.name FROM audit_events a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')
        ]);
    }
    private function setup(array $f,int $actor): string
    {
        $act=$this->field($f,'action');
        $name=trim($f['name'] ?? '');
        $branch=(int)($f['branch'] ?? 0);
        $currency=$f['currency'] ?? '';
        $note=trim($f['detail'] ?? '');
        if (strlen($note)>500) { throw new DomainException('Use a shorter description.'); }
        $run=function(string $sql,array $args):void {
            $q=$this->db->prepare($sql); $q->execute($args);
            if (!$q->rowCount()) { throw new DomainException('Check the selected record.'); }
        };
        switch ($act) {
        case 'supplier reconciliation':
            $p=$this->rows("SELECT p.*,c.decimals FROM parties p JOIN currencies c ON c.code=p.currency WHERE p.id=? AND p.kind='supplier'",[$f['party']])[0] ?? throw new DomainException('Choose a supplier.');
            $day=Books::date($this->field($f,'day'));
            $balance=$this->rows('SELECT -COALESCE(SUM(native_minor),0) AS total FROM ledger WHERE branch_id=? AND account=? AND day<=?',[$p['branch_id'],'supplier:'.$p['id'],$day])[0]['total'];
            $amount=$this->field($f,'amount');
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$amount)) { throw new DomainException('Invalid statement amount.'); }
            $minor=Amounts::parse(ltrim($amount,'-'),$p['decimals'])*(str_starts_with($amount,'-')?-1:1);
            $run('INSERT INTO supplier_checks(party_id,day,statement_minor,ledger_minor,user_id) VALUES (?,?,?,?,?)',[$p['id'],$day,$minor,$balance,$actor]); break;
        case 'company profile':
            $run('INSERT INTO company_profiles VALUES (?,?,?,?) ON CONFLICT(company_id) DO UPDATE SET address=excluded.address,contact=excluded.contact,registration=excluded.registration',
                [$f['company'],$this->field($f,'address'),$this->field($f,'contact'),$this->field($f,'registration')]); break;
        case 'numbering':
            $prefix=strtoupper($this->field($f,'prefix'));
            $next=filter_var($f['next'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999999999]]);
            if (!$next || !preg_match('/^[A-Z][A-Z0-9]{1,10}$/D',$prefix)) { throw new DomainException('Use a unique 2–11 character prefix and positive sequence.'); }
            $existing=$this->rows('SELECT MAX(CAST(substr(label,length(?)+2) AS INTEGER)) AS used FROM document_numbers WHERE substr(label,1,length(?)+1)=?||\'-\'',[$prefix,$prefix,$prefix])[0]['used'];
            if ($existing!==null && $next<=$existing) { throw new DomainException('Choose a sequence above the last issued number.'); }
            $run('INSERT INTO numbering VALUES (?,?,?) ON CONFLICT(branch_id) DO UPDATE SET prefix=excluded.prefix,next_number=excluded.next_number',[$branch,$prefix,$next]); break;
        case 'company name':
            $run('UPDATE companies SET name=? WHERE id=?',[$this->field($f,'name'),$f['company']]); break;
        case 'branch name':
            $run('UPDATE branches SET name=? WHERE id=?',[$this->field($f,'name'),$branch]); break;
        case 'service category':
            $run('INSERT INTO service_categories(name) VALUES (?)',[$this->field($f,'name')]); break;
        case 'credit limit':
        case 'supplier limit':
        case 'salary terms':
            $type=match($act) {'credit limit'=>'customer','supplier limit'=>'supplier',default=>'employee'};
            if (!in_array($type,['employee','customer','supplier'],true)) { throw new DomainException('Invalid account type.'); }
            $account=$this->rows('SELECT p.id,c.decimals FROM parties p JOIN currencies c ON c.code=p.currency WHERE p.id=? AND p.kind=?',[$f['party'],$type])[0] ?? throw new DomainException('Choose an eligible account.');
            $minor=Amounts::parse($this->field($f,'amount'),$account['decimals']);
            if ($type==='employee') {
                $run('INSERT INTO employee_terms VALUES (?,?,?) ON CONFLICT(employee_id) DO UPDATE SET job_title=excluded.job_title,salary_minor=excluded.salary_minor',[$account['id'],$this->field($f,'title'),$minor]);
            } else {
                $run('INSERT INTO credit_limits VALUES (?,?) ON CONFLICT(party_id) DO UPDATE SET limit_minor=excluded.limit_minor',[$account['id'],$minor]);
            } break;
        case 'installment':
            $row=$this->rows("SELECT o.original_minor,c.decimals FROM open_items o JOIN currencies c ON c.code=o.currency WHERE id=? AND kind='liability'",[$f['document']])[0] ?? throw new DomainException('Choose an active obligation.');
            $minor=Amounts::parse($this->field($f,'amount'),$row['decimals']);
            $planned=$this->rows('SELECT COALESCE(SUM(amount_minor),0) AS planned FROM schedules WHERE document_id=?',[$f['document']])[0]['planned'];
            if ($minor+$planned>abs($row['original_minor'])) { throw new DomainException('Installments exceed original obligation.'); }
            $run('INSERT INTO schedules(document_id,due,amount_minor) VALUES (?,?,?)',[$f['document'],Books::date($this->field($f,'day')),$minor]); break;
        case 'company':
            $run('INSERT INTO companies(name,reporting_currency) VALUES (?,?)',[$this->field($f,'name'),$currency]); break;
        case 'branch':
            $run('INSERT INTO branches(company_id,name) VALUES (?,?)',[$this->field($f,'company'),$this->field($f,'name')]); break;
        case 'currency':
            if (!preg_match('/^[0-6]$/D',$f['precision'] ?? '')) { throw new DomainException('Use 0 to 6 decimal places.'); }
            $code=strtoupper($this->field($f,'code'));
            $run('INSERT INTO currencies VALUES (?,?)',[$code,$f['precision']]);
            if (trim($f['initial_rate'] ?? '')!=='') {
                $this->setup(['action'=>'rate','currency'=>$code,'branch'=>$branch,'rate'=>$f['initial_rate'],'day'=>gmdate('Y-m-d')],$actor);
            }
            break;
        case 'rate':
            $company=$this->rows('SELECT c.reporting_currency FROM branches b JOIN companies c ON c.id=b.company_id WHERE b.id=?',[$branch])[0] ?? throw new DomainException('Check the selected record.');
            if ($currency===$company['reporting_currency']) { throw new DomainException('The reporting currency always uses a rate of 1. Choose a foreign currency.'); }
            $rate=Amounts::parse($this->field($f,'rate'),6);
            if (!$rate || $rate>1000000000000) { throw new DomainException('Rate must be positive and at most one million.'); }
            $run('INSERT INTO rates VALUES (?,?,?,?)',[$branch,$currency,Books::date($this->field($f,'day')),$f['rate']]); break;
        case 'cash':
            $run('INSERT INTO cash_accounts(company_id,branch_id,name,kind,currency) SELECT company_id,id,?,?,? FROM branches WHERE id=?',
                [$this->field($f,'name'),$f['kind'],$currency,$branch]); break;
        case 'party':
            $run('INSERT INTO parties(branch_id,kind,name,currency,detail) VALUES (?,?,?,?,?)',[$branch,$f['kind'],$this->field($f,'name'),$currency,$note]); break;
        case 'user':
            $email=$this->field($f,'email');
            $password=$f['password'] ?? '';
            if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12 || strlen($password)>72) { throw new DomainException('Check the email and enter a 12 to 72 byte password.'); }
            $run('INSERT INTO users(name,email,password,created_at,updated_at) VALUES (?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)',
                [$this->field($f,'name'),$email,password_hash($password,PASSWORD_BCRYPT)]);
            $f['user']=$this->db->lastInsertId();
            if (!empty($f['employee_id'])) {
                $employee=$this->rows("SELECT id FROM parties WHERE id=? AND branch_id=? AND kind='employee'",[$f['employee_id'],$branch])[0] ?? throw new DomainException('Choose an employee in this workspace.');
                $employeeId=$employee['id'];
            } else {
                $salaryCurrency=($f['salary_currency'] ?? '') ?: ($this->rows('SELECT c.reporting_currency FROM branches b JOIN companies c ON c.id=b.company_id WHERE b.id=?',[$branch])[0]['reporting_currency'] ?? '');
                $existing=$this->rows("SELECT id FROM parties WHERE branch_id=? AND kind='employee' AND name=?",[$branch,$name]);
                if ($existing) { throw new DomainException('Select the existing employee profile for this login.'); }
                $run("INSERT INTO parties(branch_id,kind,name,currency) VALUES (?,'employee',?,?)",[$branch,$name,$salaryCurrency]);
                $employeeId=$this->db->lastInsertId();
            }
            $run('INSERT INTO employee_logins(user_id,employee_id) VALUES (?,?)',[$f['user'],$employeeId]);
            // Grant the initial branch assignment in the same transaction.
        case 'access':
            if (($f['role'] ?? '')==='revoke') {
                $run('DELETE FROM branch_staff WHERE user_id=? AND branch_id=?',[$f['user'],$branch]);
            } else {
                $run('INSERT INTO branch_staff(user_id,company_id,branch_id,role) SELECT ?,company_id,id,? FROM branches WHERE id=?
                    ON CONFLICT(user_id,branch_id) DO UPDATE SET role=excluded.role',[$f['user'],$f['role'],$branch]);
            }
            break;
        case 'leave':
            $start=Books::date($this->field($f,'start')); $end=Books::date($this->field($f,'end'));
            if ($end<$start) { throw new DomainException('End date precedes start date.'); }
            $run("INSERT INTO staff_leave(employee_id,starts,ends,reason) SELECT id,?,?,? FROM parties WHERE id=? AND kind='employee'",[$start,$end,$note,$f['employee']]); break;
        case 'leave decision':
            if (!in_array($f['state'],['approved','declined'],true)) { throw new DomainException('Invalid leave decision.'); }
            $run("UPDATE staff_leave SET state=? WHERE id=? AND state='pending'",[$f['state'],$f['leave_id']]); break;
        case 'close period':
            $date=Books::date($this->field($f,'day'));
            if ($date>gmdate('Y-m-d')) { throw new DomainException('Choose today or an earlier date.'); }
            $run('INSERT INTO periods VALUES (?,?) ON CONFLICT(branch_id) DO UPDATE SET closed_through=excluded.closed_through WHERE excluded.closed_through>periods.closed_through',[$branch,$date]); break;
        case 'reconcile':
            $cash=$this->rows('SELECT a.*,c.decimals FROM cash_accounts a JOIN currencies c ON c.code=a.currency WHERE a.id=?',[$f['cash']])[0] ?? throw new DomainException('Choose an account.');
            $date=Books::date($this->field($f,'day'));
            $balance=$this->rows('SELECT COALESCE(SUM(native_minor),0) AS balance FROM ledger WHERE account=? AND branch_id=? AND day<=?',['cash:'.$cash['id'],$cash['branch_id'],$date])[0]['balance'];
            $amount=$this->field($f,'amount');
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D',$amount)) { throw new DomainException('Invalid statement balance.'); }
            $statement=Amounts::parse(ltrim($amount,'-'),$cash['decimals'])*(str_starts_with($amount,'-')?-1:1);
            $run('INSERT INTO reconciliations(cash_id,day,statement_minor,ledger_minor,user_id) VALUES (?,?,?,?,?)',[$cash['id'],$date,$statement,$balance,$actor]); break;
        default: throw new DomainException('Unknown setup action.');
        }
        unset($f['password'],$f['_token'],$f['action']);
        return json_encode($f,JSON_THROW_ON_ERROR);
    }
    public function matchItems(int $branch,Request $r): Response
    {
        $this->csrf($r);
        $u=$this->identity();
        if (!$u['is_admin']) { throw $this->createAccessDeniedException(); }
        $f=$r->request;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $actor=(int)$u['id'];
            $today=gmdate('Y-m-d');
            if ($this->rows('SELECT 1 FROM periods WHERE branch_id=? AND closed_through>=?',[$branch,$today])) {
                throw new DomainException('Today is in a closed period.');
            }
            if ($f->get('remove')) {
                $id=(int)$f->get('remove');
                $m=$this->rows('SELECT * FROM matches WHERE id=? AND branch_id=?',[$id,$branch])[0] ?? throw new DomainException('Allocation unavailable.');
                $this->db->prepare('INSERT INTO match_history(branch_id,account,debit_doc,credit_doc,amount_minor,user_id,request_key,fx_doc,removed_by)
                    SELECT branch_id,account,debit_doc,credit_doc,amount_minor,user_id,request_key,fx_doc,? FROM matches WHERE id=?')->execute([$actor,$id]);
                $this->db->prepare('DELETE FROM matches WHERE id=?')->execute([$id]);
                if ($m['fx_doc']) {
                    (new Books($this->db,$branch,$actor))->postWithinTransaction(['kind'=>'reverse','day'=>$today,'reference'=>'Cancel allocation '.$id,
                        'related'=>(string)$m['fx_doc'],'request_key'=>(string)Uuid::v4()]);
                }
                $detail='Removed allocation '.$id;
            } else {
                $account=(string)$f->get('account');
                $debit=(int)$f->get('debit'); $credit=(int)$f->get('credit');
                $key=(string)$f->get('request_key');
                if (!Uuid::isValid($key)) { throw new DomainException('Refresh this form.'); }
                $prior=$this->rows('SELECT id FROM matches WHERE branch_id=? AND request_key=? UNION ALL SELECT id FROM match_history WHERE branch_id=? AND request_key=?',[$branch,$key,$branch,$key]);
                if ($prior) { throw new DomainException('This allocation request was already processed.'); }
                $items=$this->rows('SELECT o.*,c.decimals FROM open_items o JOIN currencies c ON c.code=o.currency WHERE branch_id=? AND account=? AND id IN (?,?)',[$branch,$account,$debit,$credit]);
                $byId=array_column($items,null,'id');
                if (!isset($byId[$debit],$byId[$credit]) || $debit===$credit) { throw new DomainException('Choose opposite documents for the same account.'); }
                $minor=Amounts::parse((string)$f->get('amount'),$byId[$debit]['decimals']);
                if (!$minor || $minor>$byId[$debit]['outstanding_minor'] || $minor>-$byId[$credit]['outstanding_minor']) { throw new DomainException('Amount exceeds an open debit or credit.'); }
                if ($byId[$debit]['day']>$today || $byId[$credit]['day']>$today) { throw new DomainException('A future item cannot be matched today.'); }
                // Settlement allocations relieve historical carrying values; the last installment absorbs rounding.
                $carried=function(array $item,int $sign) use ($account,$minor):int {
                    $linked=$this->rows("SELECT COALESCE(SUM(e.base_minor),0) AS total FROM entries e JOIN documents d ON d.id=e.document_id
                        WHERE d.related_id=? AND e.account=? AND d.kind IN ('receipt','supplier_payment','salary_payment','repayment','customer_refund','supplier_refund')
                        AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id)",[$item['id'],$account])[0]['total'];
                    $field=$sign>0?'debit_doc':'credit_doc';
                    $baseField=$sign>0?'debit_base':'credit_base';
                    $already=$this->rows("SELECT COALESCE(SUM($baseField),0) AS total FROM matches WHERE $field=? AND account=?",[$item['id'],$account])[0]['total'];
                    return Amounts::ratio(abs($item['original_base']+$linked)-$already,$minor,abs($item['outstanding_minor']));
                };
                $dr=$carried($byId[$debit],1); $cr=$carried($byId[$credit],-1);
                $diff=$cr-$dr;
                $fx=null;
                if ($diff) {
                    $base=$this->rows('SELECT c.reporting_currency FROM branches b JOIN companies c ON c.id=b.company_id WHERE b.id=?',[$branch])[0]['reporting_currency'];
                    $this->db->prepare("INSERT INTO documents(branch_id,user_id,kind,day,due,reference,details,request_key,fingerprint) VALUES (?,?,'allocation_fx',?,?,?,'{}',?,?)")
                        ->execute([$branch,$actor,$today,$today,"Match D-$debit / D-$credit",$key,hash('sha256',$key)]);
                    $fx=$this->db->lastInsertId();
                    $q=$this->db->prepare('INSERT INTO entries(document_id,account,currency,native_minor,base_minor,rate) VALUES (?,?,?,?,?,?)');
                    $q->execute([$fx,$account,$byId[$debit]['currency'],0,$diff,'historical']);
                    $q->execute([$fx,'fx',$base,-$diff,-$diff,'1']);
                }
                $this->db->prepare('INSERT INTO matches(branch_id,account,debit_doc,credit_doc,amount_minor,user_id,request_key,fx_doc,debit_base,credit_base) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$branch,$account,$debit,$credit,$minor,$actor,$key,$fx,$dr,$cr]);
                $detail="Matched D-$debit and D-$credit on $account";
            }
            $this->db->prepare("INSERT INTO audit_events(user_id,action,detail) VALUES (?,'match',?)")->execute([$actor,$detail]);
            $this->db->exec('COMMIT');
            $this->addFlash('message',$detail);
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            if (!$e instanceof DomainException) { throw $e; }
            $this->addFlash('error',$e->getMessage());
        }
        return $this->redirectToRoute('office',['branch'=>$branch,'section'=>'dashboard']);
    }

    public function office(int $branch,string $section,Request $r): Response
    {
        $u=$this->identity();
        $scope=$this->rows("SELECT b.name,c.reporting_currency AS base,c.name AS company,
            CASE WHEN CAST(? AS INTEGER)=1 THEN 'admin' ELSE s.role END AS role FROM branches b JOIN companies c ON c.id=b.company_id
            LEFT JOIN branch_staff s ON s.branch_id=b.id AND s.user_id=? WHERE b.id=? AND (CAST(? AS INTEGER)=1 OR s.user_id IS NOT NULL)",
            [(int)$u['is_admin'],$u['id'],$branch,(int)$u['is_admin']]);
        if (!$scope) { throw $this->createNotFoundException(); }
        $info=$scope[0];
        if ($u['is_admin']) { $info['role']='admin'; }
        $books=new Books($this->db,$branch,(int)$u['id']);
        $f=[]; $preview=null;
        if ($section==='post' && $info['role']==='viewer') { throw $this->createAccessDeniedException(); }
        if ($section==='post' && $r->isMethod('GET') && $r->query->getInt('draft')) {
            $draft=$this->rows("SELECT payload FROM requests WHERE branch_id=? AND id=? AND state='pending'
                AND (requested_by=? OR CAST(? AS INTEGER)=1)",[$branch,$r->query->getInt('draft'),$u['id'],$u['is_admin']])[0] ?? throw $this->createNotFoundException();
            $f=json_decode($draft['payload'],true,512,JSON_THROW_ON_ERROR);
        }
        if ($r->isMethod('POST') && !($section==='report' && $r->query->get('view')==='statement')) {
            if ($section!=='post' || $info['role']==='viewer') { throw $this->createAccessDeniedException(); }
            $this->csrf($r);
            $f=$r->request->all();
            if (array_filter($f,'is_array')) { throw new HttpException(422,'Invalid form fields.'); }
            $mode=$f['mode'] ?? '';
            if (!in_array($mode,['preview','confirm','edit','save'],true)) { throw new HttpException(422,'Choose a transaction action.'); }
            if ($mode!=='edit') {
                $isPreview=($f['mode'] ?? '')==='preview';
                try {
                    $result=$books->transact($f,$isPreview);
                    if (!$isPreview || $result['id']) {
                        $this->addFlash('message','Saved to the ledger.');
                        return $this->redirectToRoute('office',['branch'=>$branch,'section'=>'report','doc'=>$result['id']]);
                    }
                    $preview=$result['lines'];
                } catch (DomainException $e) {
                    if ($e->getMessage()==='Administrator access required.') {
                        $this->db->prepare("INSERT INTO audit_events(user_id,action,detail) VALUES (?,'permission denied',?)")->execute([$u['id'],'Branch '.$branch.' · '.($f['kind'] ?? '')]);
                    }
                    $this->addFlash('error',$e->getMessage());
                }
            }
        }
        $prec=array_column($this->rows('SELECT * FROM currencies'),'decimals','code');
        $parties=$this->rows('SELECT * FROM parties WHERE branch_id=? ORDER BY kind,name',[$branch]);
        $cash=$this->rows('SELECT * FROM cash_accounts WHERE branch_id=? ORDER BY name',[$branch]);
        $names=['sales'=>'Sales revenue','service_cost'=>'Supplier costs','salary_expense'=>'Payroll expense','expense'=>'Other expenses','equity'=>'Owner equity','fx'=>'Exchange differences'];
        $names=array_map([$this->language,'label'],$names);
        foreach ($cash as $a) { $names['cash:'.$a['id']]=$a['name']; }
        foreach ($parties as $a) {
            $names[$a['kind'].':'.$a['id']]=$a['name'].' ('.$this->language->label($a['kind']).')';
            if ($a['kind']==='employee') { $names['advance:'.$a['id']]=$a['name'].' ('.$this->language->label('Advance').')'; }
        }
        $query=$r->query;
        if ($section==='report' && $query->get('view')==='statement') {
            $accounts=[];
            foreach ($parties as $party) {
                $accounts[$party['kind'].':'.$party['id']]=['group'=>ucfirst($party['kind']),'name'=>$party['name'],'currency'=>$party['currency']];
                if ($party['kind']==='employee') {
                    $accounts['advance:'.$party['id']]=['group'=>'Staff advances','name'=>$party['name'],'currency'=>$party['currency']];
                }
            }
            foreach ($cash as $a) {
                $accounts['cash:'.$a['id']]=['group'=>ucfirst($a['kind']),'name'=>$a['name'],'currency'=>$a['currency']];
            }
            foreach ($names as $key=>$label) {
                if (!str_contains($key,':')) { $accounts[$key]=['group'=>'General ledger','name'=>$label,'currency'=>$info['base']]; }
            }
            $groups=[];
            foreach ($accounts as $key=>$item) { $groups[$item['group']][$key]=$item; }
            $selected=$query->get('account','');
            if ($selected!=='' && !isset($accounts[$selected])) { throw $this->createNotFoundException(); }
            $today=gmdate('Y-m-d');
            $from=$query->get('from') ?: substr($today,0,8).'01';
            $to=$query->get('to') ?: $today;
            $period=$query->get('period','');
            if (in_array($period,['month','last','year'],true)) {
                $month=new \DateTimeImmutable(substr($today,0,8).'01',new \DateTimeZone('UTC'));
                $from=match($period) {'month'=>$month->format('Y-m-d'),'last'=>$month->modify('-1 month')->format('Y-m-d'),'year'=>substr($today,0,4).'-01-01'};
                $to=$period==='last'?$month->modify('-1 day')->format('Y-m-d'):$today;
            }
            $checking=$r->isMethod('POST');
            if ($checking) {
                if (!$u['is_admin']) { throw $this->createAccessDeniedException(); }
                $this->csrf($r);
                $this->db->exec('BEGIN IMMEDIATE');
            }
            $statement=null; $error=null; $account=$accounts[$selected] ?? null;
            try {
                Books::date($from); Books::date($to);
                if ($from>$to) { throw new DomainException('Start date is after end date.'); }
                if ($account) {
                    $statement=(new AccountStatement($this->db))->read($branch,$selected,$account['currency'],$prec[$account['currency']],$from,$to);
                }
                if ($checking) {
                    if (!$statement || !$account) { throw new DomainException('Choose an account.'); }
                    $side=$r->request->get('side');
                    if (!in_array($side,['Dr','Cr'],true)) { throw new DomainException('Select debit or credit.'); }
                    $external=Amounts::parse(trim((string)$r->request->get('external')),$prec[$account['currency']])*($side==='Cr'?-1:1);
                    $insert=$this->db->prepare('INSERT INTO statement_checks(branch_id,account,currency,as_of,ledger_minor,external_minor,user_id)
                        VALUES (?,?,?,?,?,?,?) ON CONFLICT(branch_id,account,as_of,ledger_minor,external_minor) DO NOTHING');
                    $insert->execute([$branch,$selected,$account['currency'],$to,$statement['closing_minor'],$external,$u['id']]);
                    if ($insert->rowCount()) {
                        $this->db->prepare("INSERT INTO audit_events(user_id,action,detail) VALUES (?,'statement reconciliation',?)")
                            ->execute([$u['id'],$selected.' '.$to]);
                    }
                    $this->db->exec('COMMIT');
                    return $this->redirectToRoute('office',['branch'=>$branch,'section'=>'report','view'=>'statement','account'=>$selected,'from'=>$from,'to'=>$to]);
                }
            } catch (\Throwable $e) {
                if ($checking) { $this->db->exec('ROLLBACK'); }
                if (!$e instanceof DomainException) { throw $e; }
                $error=$e->getMessage();
            }
            $checks=[];
            if ($statement && $u['is_admin']) {
                $checks=$this->rows('SELECT s.*,u.name AS staff FROM statement_checks s JOIN users u ON u.id=s.user_id
                    WHERE s.branch_id=? AND s.account=? AND s.as_of=? ORDER BY s.id DESC LIMIT 5',[$branch,$selected,$to]);
                foreach ($checks as &$check) {
                    $check['difference']=Amounts::format($check['external_minor']-$check['ledger_minor'],$prec[$account['currency']]);
                    $check['external']=Amounts::format(abs($check['external_minor']),$prec[$account['currency']]);
                } unset($check);
            }
            $profile=$this->rows('SELECT p.* FROM company_profiles p JOIN branches b ON b.company_id=p.company_id WHERE b.id=?',[$branch])[0] ?? [];
            if ($query->get('download')==='csv' && $statement) {
                $file=fopen('php://temp','w+');
                fwrite($file,"\xEF\xBB\xBF");
                $write=static function(array $cells) use ($file):void {
                    fputcsv($file,array_map(static fn($v)=>preg_match('/^[\\s]*[=+@-]/',(string)$v)?"'".$v:$v,$cells));
                };
                $tr=[$this->language,'label'];
                $write([$info['company'],$account['name'],$account['currency'],$from,$to]);
                $write(array_map($tr,['Date','Document','Operation','Reference','Booking reference (PNR)','Ticket number','Debit','Credit','Balance','Dr / Cr']));
                $write([$from,'',$tr('Opening balance'),'','','','','',$statement['opening']['amount'],$tr($statement['opening']['side'])]);
                foreach ($statement['lines'] as $line) {
                    $write([$line['day'],$line['number'],$tr($line['operation']),$line['reference'],$line['pnr'],$line['ticket'],$line['debit'],$line['credit'],$line['balance']['amount'],$tr($line['balance']['side'])]);
                }
                $write([$to,'',$tr('Closing balance'),'','','',$statement['debits'],$statement['credits'],$statement['closing']['amount'],$tr($statement['closing']['side'])]);
                rewind($file);
                return new Response(stream_get_contents($file),200,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="statement-'.$from.'-'.$to.'.csv"']);
            }
            return $this->render('account_statement.html.twig',compact('branch','info','groups','account','selected','from','to','today','statement','profile','error','checks'));
        }
        if ($section==='statements') {
            try {
                $from=Books::date($query->get('from') ?: substr(gmdate('Y-m-d'),0,4).'-01-01');
                $to=Books::date($query->get('to') ?: gmdate('Y-m-d'));
                if ($from>$to) { throw new DomainException('Start date is after end date.'); }
            } catch (DomainException $e) { throw new HttpException(422,$e->getMessage()); }
            $report=(new Reports($this->db))->statements($branch,$from,$to,$query->get('group','service'));
            $netProfit=Amounts::format(array_sum($report['profit']),$prec[$info['base']]);
            foreach (['profit','sheet'] as $part) {
                foreach ($report[$part] as &$v) { $v=Amounts::format($v,$prec[$info['base']]); } unset($v);
            }
            foreach ($report['performance'] as &$row) {
                $row['margin']=Amounts::format($row['sales']-$row['cost'],$prec[$info['base']]);
                foreach (['sales','cost'] as $col) { $row[$col]=Amounts::format($row[$col],$prec[$info['base']]); }
            } unset($row);
            foreach ($report['flows'] as &$row) { $row['amount']=Amounts::format($row['amount'],$prec[$info['base']]); } unset($row);
            foreach ($report['exposure'] as &$row) {
                $row['native']=Amounts::format($row['native'],$prec[$row['currency']]);
                $row['base']=Amounts::format($row['base'],$prec[$info['base']]);
            } unset($row);
            return $this->render('statements.html.twig',compact('report','info','from','to','names','branch','netProfit'));
        }
        $where=['branch_id=?']; $args=[$branch];
        foreach (['from'=>'day>=?','to'=>'day<=?','account'=>'account=?','user'=>'user_id=?','doc'=>"number='D-'||?",'number'=>'number=?'] as $key=>$clause) {
            if (($value=$query->get($key,''))!=='') { $where[]=$clause; $args[]=$value; }
        }
        if (($term=trim($query->get('search','')))!=='') {
            $where[]="(instr(lower(reference),lower(?))>0 OR number IN (SELECT 'D-'||id FROM documents WHERE branch_id=? AND instr(lower(details),lower(?))>0))";
            array_push($args,$term,$branch,$term);
        }
        $condition=implode(' AND ',$where);
        $totals=$this->rows("SELECT account,currency,SUM(native_minor) AS native,SUM(base_minor) AS base FROM ledger WHERE $condition GROUP BY account,currency ORDER BY account",$args);
        $limit=$section==='dashboard'?' LIMIT 12':'';
        $lines=$this->rows("SELECT * FROM ledger WHERE $condition ORDER BY day DESC,number DESC,account$limit",$args);
        foreach ($lines as &$l) {
            $l['name']=$names[$l['account']] ?? $l['account'];
            $l['amount']=Amounts::format($l['native_minor'],$prec[$l['currency']]);
            $l['report']=Amounts::format($l['base_minor'],$prec[$info['base']]);
        } unset($l);
        if ($section==='workbook') {
            $xml=static fn($s)=>htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $data='<?xml version="1.0" encoding="UTF-8"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Ledger"><Table>';
            $write=static function(array $values,bool $numeric=false) use (&$data,$xml):void {
                $data.='<Row>';
                foreach ($values as $i=>$v) {
                    $type=$numeric && $i>=6?'Number':'String';
                    $data.='<Cell><Data ss:Type="'.$type.'">'.$xml($v).'</Data></Cell>';
                }
                $data.='</Row>';
            };
            $write(array_map([$this->language,'label'],['Date','Document','Operation','Reference','Account','Currency','Native movement','Reporting movement']));
            foreach ($lines as $l) { $write([$l['day'],$l['number'],$this->language->label(Books::TYPES[$l['kind']] ?? ucfirst(str_replace('_',' ',$l['kind']))),$l['reference'],$l['name'],$l['currency'],$l['amount'],$l['report']],true); }
            return new Response($data.'</Table></Worksheet></Workbook>',200,['Content-Type'=>'application/vnd.ms-excel','Content-Disposition'=>'attachment; filename="accounting-ledger.xml"']);
        }
        if ($section==='export') {
            $file=fopen('php://temp','w+');
            fputcsv($file,array_map([$this->language,'label'],['Date','Document','Operation','Reference','Account','Currency','Account movement','Reporting movement']));
            foreach ($lines as $l) {
                $safe=static fn($v)=>preg_match('/^[\s]*[=+@-]/',$v)?"'".$v:$v;
                fputcsv($file,[$l['day'],$l['number'],$this->language->label(Books::TYPES[$l['kind']] ?? ucfirst(str_replace('_',' ',$l['kind']))),$safe($l['reference']),$safe($l['name']),$l['currency'],$l['amount'],$l['report']]);
            }
            rewind($file);
            return new Response(stream_get_contents($file),200,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="ledger.csv"']);
        }
        $metrics=array_fill_keys(['Cash','Receivables','Supplier payables','Other obligations','Sales','Supplier costs','Gross profit','Net profit','Cash after obligations'],0);
        foreach ($totals as &$t) {
            $code=explode(':',$t['account'])[0]; $v=(int)$t['base'];
            switch ($code) {
            case 'cash': $metrics['Cash']+=$v; break;
            case 'customer':
                $metrics['Receivables']+=max(0,$v);
                $metrics['Other obligations']+=max(0,-$v); break;
            case 'supplier': $metrics['Supplier payables']+=max(0,-$v); break;
            case 'creditor': case 'employee': $metrics['Other obligations']+=max(0,-$v); break;
            case 'sales': $metrics['Sales']-=$v; break;
            case 'service_cost': $metrics['Supplier costs']+=$v; break;
            }
            if (in_array($code,['sales','service_cost','salary_expense','expense','fx'],true)) { $metrics['Net profit']-=$v; }
            $t['amount']=Amounts::format($t['native'],$prec[$t['currency']]);
            $t['report']=Amounts::format($v,$prec[$info['base']]);
        } unset($t);
        $metrics['Gross profit']=$metrics['Sales']-$metrics['Supplier costs'];
        $metrics['Cash after obligations']=$metrics['Cash']-$metrics['Supplier payables']-$metrics['Other obligations'];
        foreach ($metrics as &$v) { $v=Amounts::format($v,$prec[$info['base']]); } unset($v);
        $doc=$query->getInt('doc') ? ($this->rows('SELECT d.*,u.name AS staff,n.label,cp.address,cp.contact,cp.registration FROM documents d JOIN users u ON u.id=d.user_id LEFT JOIN document_numbers n ON n.document_id=d.id JOIN branches b ON b.id=d.branch_id LEFT JOIN company_profiles cp ON cp.company_id=b.company_id WHERE d.branch_id=? AND d.id=?',[$branch,$query->getInt('doc')])[0] ?? null) : null;
        $aging=$this->rows('SELECT * FROM open_items WHERE branch_id=? AND outstanding_minor<>0 ORDER BY due,id',[$branch]);
        foreach ($aging as &$item) { $item['amount']=Amounts::format($item['outstanding_minor'],$prec[$item['currency']]); } unset($item);
        $schedule=$this->rows("SELECT s.*,p.name,p.currency FROM schedules s JOIN documents d ON d.id=s.document_id JOIN entries e ON e.document_id=d.id
            JOIN parties p ON e.account='creditor:'||p.id WHERE d.branch_id=? AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id) ORDER BY s.due",[$branch]);
        foreach ($schedule as &$item) { $item['amount']=Amounts::format($item['amount_minor'],$prec[$item['currency']]); } unset($item);
        $matches=$info['role']==='admin' ? $this->rows('SELECT id,debit_doc,credit_doc,account FROM matches WHERE branch_id=? ORDER BY id DESC',[$branch]) : [];
        $recentSales=$section==='dashboard' ? $this->rows("SELECT d.id,d.day,d.reference,
            MAX(p.name) AS customer,
            -SUM(CASE WHEN e.account='sales' THEN e.base_minor ELSE 0 END) AS revenue,
            SUM(CASE WHEN e.account='service_cost' THEN e.base_minor ELSE 0 END) AS cost,
            EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id) AS reversed
            FROM documents d JOIN entries e ON e.document_id=d.id
            LEFT JOIN parties p ON p.branch_id=d.branch_id AND e.account='customer:'||p.id
            WHERE d.branch_id=? AND d.kind IN ('sale','reissue','refund')
            GROUP BY d.id ORDER BY d.id DESC LIMIT 10",[$branch]) : [];
        foreach ($recentSales as &$sale) {
            $sale['margin']=Amounts::format($sale['revenue']-$sale['cost'],$prec[$info['base']]);
            $sale['total']=Amounts::format($sale['revenue'],$prec[$info['base']]);
        } unset($sale);
        $pending=$section==='dashboard' && $info['role']!=='viewer' ? $this->rows("SELECT r.*,u.name FROM requests r JOIN users u ON u.id=r.requested_by
            WHERE r.branch_id=? AND r.state='pending' AND (r.requested_by=? OR CAST(? AS INTEGER)=1) ORDER BY r.id",[$branch,$u['id'],$u['is_admin']]) : [];
        foreach ($pending as &$req) { $req['values']=json_decode($req['payload'],true); } unset($req);
        $checks=$this->rows("SELECT s.*,p.name,p.currency,c.decimals FROM supplier_checks s JOIN parties p ON p.id=s.party_id JOIN currencies c ON c.code=p.currency WHERE p.branch_id=? ORDER BY s.day DESC",[$branch]);
        foreach ($checks as &$item) { $item['variance']=Amounts::format($item['statement_minor']-$item['ledger_minor'],$item['decimals']); } unset($item);
        $services=$this->rows('SELECT name FROM service_categories ORDER BY name');
        $recs=$this->rows('SELECT r.*,a.name,a.currency,c.decimals FROM reconciliations r JOIN cash_accounts a ON a.id=r.cash_id JOIN currencies c ON c.code=a.currency WHERE a.branch_id=? ORDER BY r.day DESC',[$branch]);
        foreach ($recs as &$rec) { $rec['variance']=Amounts::format($rec['statement_minor']-$rec['ledger_minor'],$rec['decimals']); } unset($rec);
        if ($doc) { $doc['details']=json_decode($doc['details'],true); }
        foreach ($preview ?? [] as $i=>$e) {
            $preview[$i]['native']=Amounts::format($e['native'],$prec[$e['currency']]);
            $preview[$i]['base']=Amounts::format($e['base'],$prec[$info['base']]);
        }
        return $this->render('office.html.twig',compact('section','info','branch','metrics','parties','cash','names','doc','lines','totals','prec','preview','f','aging','schedule','services','recs','pending','checks','matches')+[
            'recentSales'=>$recentSales,'adminTypes'=>Books::ADMIN_TYPES,
            'recentDocs'=>$section==='post' ? $this->rows('SELECT id,reference,day FROM documents d WHERE branch_id=? AND reversal_of IS NULL AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id) ORDER BY id DESC LIMIT 100',[$branch]) : [],
            'types'=>Books::TYPES,'key'=>(string)Uuid::v4(),'today'=>gmdate('Y-m-d'),
            'staff'=>$this->rows('SELECT DISTINCT u.id,u.name FROM users u JOIN ledger l ON l.user_id=u.id WHERE branch_id=?',[$branch]),

        ]);
    }
}
