<?php
declare(strict_types=1);

namespace App\Accounting;

use PDO;

final class AccountStatement
{
    public function __construct(private PDO $db) {}

    /** One SQLite read snapshot covers the opening, movements and closing balance. A deleted entry and its reversal are left out when both fall in the period, since together they net to zero. */
    public function read(int $branch,string $account,string $currency,int $precision,string $from,string $to): array
    {
        // The document join takes the id out of the D-number so SQLite can look it up directly instead of scanning the branch.
        $query=$this->db->prepare("SELECT CASE WHEN l.day < :start THEN '' ELSE l.day END AS day,
            CASE WHEN l.day < :start THEN '' ELSE l.number END AS document,
            MAX(l.reference) AS reference,MAX(l.kind) AS kind,
            SUM(CASE WHEN l.native_minor>0 THEN l.native_minor ELSE 0 END) AS debit,
            SUM(CASE WHEN l.native_minor<0 THEN -l.native_minor ELSE 0 END) AS credit,
            MAX(CASE WHEN l.day>=:start THEN n.label END) AS label,
            MAX(CASE WHEN l.day>=:start THEN d.details END) AS details,
            MAX(CASE WHEN l.day>=:start THEN d.id END) AS doc_id,
            MAX(CASE WHEN l.day>=:start AND EXISTS(SELECT 1 FROM documents r WHERE r.reversal_of=d.id) THEN 1 ELSE 0 END) AS reversed
            FROM ledger l LEFT JOIN documents d ON d.id=CASE WHEN substr(l.number,1,2)='D-' THEN CAST(substr(l.number,3) AS INTEGER) END AND d.branch_id=l.branch_id
            LEFT JOIN document_numbers n ON n.document_id=d.id
            WHERE l.branch_id=:branch AND l.account=:account AND l.currency=:currency AND l.day<=:end AND l.native_minor<>0
            AND NOT (l.day>=:start AND d.id IS NOT NULL AND (EXISTS(SELECT 1 FROM documents r WHERE r.reversal_of=d.id AND r.day<=:end)
                OR EXISTS(SELECT 1 FROM documents o WHERE o.id=d.reversal_of AND o.day>=:start)))
            GROUP BY 1,2 ORDER BY 1,substr(document,1,1),CAST(substr(document,3) AS INTEGER)");
        $query->execute(['branch'=>$branch,'account'=>$account,'currency'=>$currency,'start'=>$from,'end'=>$to]);
        $opening=$debits=$credits=$balance=0;
        $lines=[];
        $format=static fn(int $value):string=>Amounts::format($value,$precision);
        $signed=static fn(int $value):array=>['amount'=>$format(abs($value)), 'side'=>$value>0?'Dr':($value<0?'Cr':'')];
        foreach ($query as $row) {
            $debit=(int)$row['debit']; $credit=(int)$row['credit'];
            $balance += $debit-$credit;
            if ($row['day']==='') { $opening=$balance; continue; }
            $debits+=$debit; $credits+=$credit;
            $details=json_decode($row['details'] ?? '{}',true) ?: [];
            $lines[]=['day'=>$row['day'],'number'=>$row['label'] ?: $row['document'],'reference'=>$row['reference'],
                'doc'=>$row['doc_id'] && !$row['reversed'] && !in_array($row['kind'],['reverse','allocation_fx','revaluation'],true) ? (int)$row['doc_id'] : 0,
                'operation'=>Books::TYPES[$row['kind']] ?? ucfirst(str_replace('_',' ',$row['kind'])),
                'pnr'=>$details['pnr'] ?? '', 'ticket'=>$details['ticket'] ?? '',
                'debit'=>$format($debit),'credit'=>$format($credit),'balance'=>$signed($balance)];
        }
        return ['lines'=>$lines,'opening'=>$signed($opening),'closing'=>$signed($balance),
            'closing_minor'=>$balance,'debits'=>$format($debits),'credits'=>$format($credits)];
    }
}
