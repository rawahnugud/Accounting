<?php
declare(strict_types=1);
namespace App\Accounting;

use DomainException;
use PDO;
use Symfony\Component\Uid\Uuid;

final class Corrections
{
    private Books $books;
    public const IMPORTS=['legacy_ticket_cost','legacy_ticket_refund','legacy_supplier_payment','legacy_journal'];

    public function __construct(private PDO $db, private int $branch, private int $actor)
    {
        $this->books=new Books($db,$branch,$actor);
    }

    private function original(int $id): array
    {
        // Administrators and the branch's sales staff may correct entries.
        $row=$this->books->rows('SELECT d.* FROM documents d JOIN users u ON u.id=? AND (u.is_admin=1
            OR EXISTS(SELECT 1 FROM branch_staff s WHERE s.user_id=u.id AND s.branch_id=d.branch_id AND s.role=\'cashier\'))
            WHERE d.id=? AND d.branch_id=? AND d.reversal_of IS NULL AND d.kind NOT IN (\'allocation_fx\',\'reverse\',\'revaluation\')
            AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id)',[$this->actor,$id,$this->branch]);
        return $row[0] ?? throw new DomainException('This document is unavailable for correction.');
    }

    public function form(int $id): array
    {
        $doc=$this->original($id);
        $meta=json_decode($doc['details'],true,512,JSON_THROW_ON_ERROR);
        $f=['kind'=>$doc['kind'],'day'=>$doc['day'],'due'=>$doc['due'],'reference'=>$doc['reference'],
            'related'=>$doc['related_id'],'amend'=>$id,'currency'=>$meta['source'] ?? '']+$meta;
        if (!empty($meta['override_by'])) { $f['override']=$meta['override'] ?? $meta['source_rate'] ?? ''; }
        if ($doc['kind']==='payroll') { $f['amount']=$meta['salary'] ?? ''; }
        foreach ($this->books->rows('SELECT account,native_minor FROM entries WHERE document_id=? ORDER BY id',[$id]) as $line) {
            $parts=explode(':',$line['account'],2);
            if (count($parts)!==2) { continue; }
            [$field,$value]=$parts;
            if ($field==='cash' && $doc['kind']==='transfer' && $line['native_minor']>0) { $field='destination'; }
            if ($field==='advance') { $field='employee'; }
            $f[$field]=$value;
        }
        return $f;
    }

    public function save(int $id,array $f,bool $void): int
    {
        $reason=trim($f['reason'] ?? '');
        if ($reason==='' || strlen($reason)>500) { throw new DomainException('Enter a correction reason (maximum 500 bytes).'); }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $doc=$this->original($id);
            // A document in a closed period is reversed today, leaving the closed books unchanged.
            $closed=$this->books->rows('SELECT closed_through FROM periods WHERE branch_id=?',[$this->branch])[0]['closed_through'] ?? '';
            $reversal=$this->books->postWithinTransaction(['kind'=>'reverse','related'=>$id,'day'=>$doc['day']>$closed?$doc['day']:date('Y-m-d'),
                'reference'=>"Deleted D-$id",'note'=>$reason,'request_key'=>(string)Uuid::v4()])['id'];
            $result=$reversal;
            $detail="D-$id; reversal D-$reversal";
            if (!$void) {
                if (in_array($doc['kind'],self::IMPORTS,true)) {
                    $result=$this->replaceImport($doc,$f);
                } else {
                    $f['kind']=$doc['kind'];
                    $f['request_key']=(string)Uuid::v4();
                    $result=$this->books->postWithinTransaction($f)['id'];
                }
                $detail.="; replacement D-$result";
            }
            if (!$void) {
                $this->db->prepare('UPDATE schedules SET document_id=? WHERE document_id=?')->execute([$result,$id]);
                $planned=$this->books->rows('SELECT sum(amount_minor) AS total FROM schedules WHERE document_id=?',[$result])[0]['total'];
                if ($planned && !$this->books->rows("SELECT id FROM open_items WHERE id=? AND kind IN ('liability','loan') AND abs(original_minor)>=CAST(? AS INTEGER)",[$result,(int)$planned])) {
                    throw new DomainException('Installments exceed original obligation.');
                }
            }
            $this->db->prepare('INSERT INTO audit_events(user_id,action,detail) VALUES(?,?,?)')
                ->execute([$this->actor,$void?'Delete document':'Amend document',$detail.' · '.$reason]);
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Re-posts every eligible entry in a date range at a new rate, optionally for one account.
     * Each document is its own transaction, so one blocked entry (for example an invoice with linked payments) does not stop the rest.
     */
    public function rerate(string $currency,string $from,string $to,string $rate,string $reason,string $account=''): array
    {
        Books::date($from); Books::date($to);
        if ($from>$to) { throw new DomainException('Start date is after end date.'); }
        $rate=Amounts::clean($rate);
        if (!Amounts::parse($rate,6)) { throw new DomainException('Enter a positive exchange rate up to one million, for an operation that involves a foreign currency.'); }
        if (trim($reason)==='') { throw new DomainException('Enter a correction reason (maximum 500 bytes).'); }
        $ids=array_column($this->books->rows("SELECT DISTINCT d.id FROM documents d JOIN entries e ON e.document_id=d.id
            WHERE d.branch_id=? AND d.day BETWEEN ? AND ? AND e.currency=? AND d.reversal_of IS NULL
            AND d.kind NOT IN ('reverse','allocation_fx','revaluation','payroll','legacy_ticket_cost','legacy_ticket_refund','legacy_supplier_payment','legacy_journal')
            AND NOT EXISTS(SELECT 1 FROM documents x WHERE x.reversal_of=d.id)
            AND (?='' OR EXISTS(SELECT 1 FROM entries a WHERE a.document_id=d.id AND a.account=?))
            ORDER BY d.id DESC LIMIT 200",[$this->branch,$from,$to,$currency,$account,$account]),'id');
        $done=[]; $skipped=[];
        foreach ($ids as $id) {
            try {
                $f=$this->form((int)$id);
                if (($f['source_rate'] ?? '')===$rate || ($f['override'] ?? '')===$rate) { continue; }
                $done[$id]=$this->save((int)$id,['override'=>$rate,'reason'=>$reason]+$f,false);
            } catch (DomainException $e) { $skipped[$id]=$e->getMessage(); }
        }
        return ['done'=>$done,'skipped'=>$skipped];
    }

    private function replaceImport(array $doc,array $f): int
    {
        $lines=$this->books->rows('SELECT * FROM entries WHERE document_id=? ORDER BY id',[$doc['id']]);
        $amount=Amounts::parse(trim($f['amount'] ?? ''),2);
        $ref=trim($f['reference'] ?? '');
        $ticket=trim($f['ticket'] ?? '');
        if (!$amount || $ref==='' || strlen($ref)>160 || strlen($ticket)>100 || count($lines)!==2
            || $lines[0]['native_minor']!=-$lines[1]['native_minor']) { throw new DomainException('Check the amount and reference.'); }
        $meta=json_decode($doc['details'],true,512,JSON_THROW_ON_ERROR);
        $meta['ticket']=$ticket;
        $meta['corrects_document']=$doc['id'];
        $key=(string)Uuid::v4();
        $this->db->prepare('INSERT INTO documents(branch_id,user_id,kind,day,due,reference,details,request_key,fingerprint) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$this->branch,$this->actor,$doc['kind'],$doc['day'],$doc['due'],$ref,json_encode($meta,JSON_THROW_ON_ERROR),$key,hash('sha256',$key)]);
        $id=(int)$this->db->lastInsertId();
        $insert=$this->db->prepare("INSERT INTO entries(document_id,account,currency,native_minor,base_minor,rate) VALUES(?,?,'USD',?,?,'1')");
        foreach ($lines as $line) {
            if ($line['currency']!=='USD' || $line['rate']!=='1' || !$line['native_minor'] || $line['native_minor']!=$line['base_minor']) {
                throw new DomainException('This import needs manual reconciliation.');
            }
            $signed=$line['native_minor']>0?$amount:-$amount;
            $insert->execute([$id,$line['account'],$signed,$signed]);
        }
        return $id;
    }
}
