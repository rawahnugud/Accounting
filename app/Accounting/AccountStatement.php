<?php
declare(strict_types=1);

namespace App\Accounting;

use PDO;

final class AccountStatement
{
    public function __construct(private PDO $db) {}

    /** One SQLite read snapshot covers the opening, movements and closing balance. */
    public function read(int $branch,string $account,string $currency,int $precision,string $from,string $to): array
    {
        $query=$this->db->prepare("SELECT CASE WHEN l.day < :start THEN '' ELSE l.day END AS day,
            CASE WHEN l.day < :start THEN '' ELSE l.number END AS document,
            MAX(l.reference) AS reference,MAX(l.kind) AS kind,
            SUM(CASE WHEN l.native_minor>0 THEN l.native_minor ELSE 0 END) AS debit,
            SUM(CASE WHEN l.native_minor<0 THEN -l.native_minor ELSE 0 END) AS credit,
            MAX(CASE WHEN l.day>=:start THEN n.label END) AS label,
            MAX(CASE WHEN l.day>=:start THEN d.details END) AS details
            FROM ledger l LEFT JOIN documents d ON l.number='D-'||d.id AND d.branch_id=l.branch_id
            LEFT JOIN document_numbers n ON n.document_id=d.id
            WHERE l.branch_id=:branch AND l.account=:account AND l.currency=:currency AND l.day<=:end
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
                'operation'=>Books::TYPES[$row['kind']] ?? ucfirst(str_replace('_',' ',$row['kind'])),
                'pnr'=>$details['pnr'] ?? '', 'ticket'=>$details['ticket'] ?? '',
                'debit'=>$format($debit),'credit'=>$format($credit),'balance'=>$signed($balance)];
        }
        return ['lines'=>$lines,'opening'=>$signed($opening),'closing'=>$signed($balance),
            'closing_minor'=>$balance,'debits'=>$format($debits),'credits'=>$format($credits)];
    }
}
