<?php
declare(strict_types=1);
namespace App\Accounting;

use PDO;

final class Reports
{
    public function __construct(private PDO $db) {}

    /** Period performance and cash flows use movements. The balance sheet is cumulative through the end date. */
    public function statements(int $branch,string $from,string $to,string $group): array
    {
        $dimensions=[
            'service'=>'COALESCE(original.details,d.details)',
            'airline'=>'COALESCE(original.details,d.details)',
            'employee'=>'u.name',
            'supplier'=>"(SELECT GROUP_CONCAT(p.name,', ') FROM entries a JOIN parties p ON a.account='supplier:'||p.id WHERE a.document_id=COALESCE(d.reversal_of,d.id))",
            'customer'=>"(SELECT GROUP_CONCAT(p.name,', ') FROM entries a JOIN parties p ON a.account='customer:'||p.id WHERE a.document_id=COALESCE(d.reversal_of,d.id))"
        ];
        $group=isset($dimensions[$group])?$group:'service';
        $dimension=$dimensions[$group];
        $rows=function(string $sql,array $args):array {
            $q=$this->db->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
        };
        $income=$rows("SELECT account,SUM(base_minor) AS amount FROM ledger WHERE branch_id=? AND day BETWEEN ? AND ?
            AND account IN ('sales','expense','service_cost','salary_expense','fx') GROUP BY account",[$branch,$from,$to]);
        $profit=[];
        foreach ($income as $r) { $profit[$r['account']]=-$r['amount']; }
        // Split other expenses by the category saved on fee and creditor obligation documents; a reversal uses its original's category.
        if (isset($profit['expense'])) {
            unset($profit['expense']);
            foreach ($rows("SELECT COALESCE(o.details,d.details) AS details,SUM(e.base_minor) AS amount FROM entries e JOIN documents d ON d.id=e.document_id
                LEFT JOIN documents o ON o.id=d.reversal_of WHERE d.branch_id=? AND d.day BETWEEN ? AND ? AND e.account='expense' GROUP BY 1",[$branch,$from,$to]) as $r) {
                $label=(json_decode($r['details'],true)['category'] ?? '') ?: 'expense';
                $profit[$label]=($profit[$label] ?? 0)-$r['amount'];
            }
        }
        $balances=$rows("SELECT account,SUM(base_minor) AS amount FROM ledger WHERE branch_id=? AND day<=? GROUP BY account",[$branch,$to]);
        $sheet=['Cash'=>0,'Receivables'=>0,'Staff advances'=>0,'Supplier prepayments'=>0,'Customer deposits'=>0,
            'Supplier payables'=>0,'Creditor obligations'=>0,'Payroll payable'=>0,'Owner equity'=>0,'Accumulated earnings'=>0];
        foreach ($balances as $r) {
            $amount=(int)$r['amount'];
            switch (explode(':',$r['account'])[0]) {
            case 'cash': $sheet['Cash']+=$amount; break;
            case 'customer': $sheet[$amount>=0?'Receivables':'Customer deposits']+=abs($amount); break;
            case 'supplier': $sheet[$amount>=0?'Supplier prepayments':'Supplier payables']+=abs($amount); break;
            case 'advance': $sheet['Staff advances']+=$amount; break;
            case 'employee': $sheet['Payroll payable']-=$amount; break;
            case 'creditor': $sheet['Creditor obligations']-=$amount; break;
            case 'equity': $sheet['Owner equity']-=$amount; break;
            default: $sheet['Accumulated earnings']-=$amount;
            }
        }
        $assets=$sheet['Cash']+$sheet['Receivables']+$sheet['Staff advances']+$sheet['Supplier prepayments'];
        $claims=array_sum(array_slice($sheet,4));
        $sheet=array_slice($sheet,0,4)+['Total assets'=>$assets]+array_slice($sheet,4)+['Total liabilities and equity'=>$claims];
        if ($assets!==$claims) { $sheet['Unbalanced difference']=$assets-$claims; }
        $flows=$rows("SELECT kind,SUM(base_minor) AS amount FROM ledger WHERE branch_id=? AND day BETWEEN ? AND ? AND account LIKE 'cash:%' GROUP BY kind",[$branch,$from,$to]);
        $performance=$rows("SELECT COALESCE(NULLIF($dimension,''),'Unspecified') AS label,
            -SUM(CASE WHEN e.account='sales' THEN e.base_minor ELSE 0 END) AS sales,
            SUM(CASE WHEN e.account='service_cost' THEN e.base_minor ELSE 0 END) AS cost
            FROM documents d JOIN entries e ON e.document_id=d.id JOIN users u ON u.id=d.user_id
            LEFT JOIN documents original ON original.id=d.reversal_of
            WHERE d.branch_id=? AND d.day BETWEEN ? AND ? AND e.account IN ('sales','service_cost') GROUP BY label ORDER BY label",[$branch,$from,$to]);
        if (in_array($group,['service','airline'],true)) {
            $grouped=[];
            foreach ($performance as $r) {
                $label=(json_decode($r['label'],true)[$group] ?? '') ?: 'Unspecified';
                $grouped[$label] ??= ['label'=>$label,'sales'=>0,'cost'=>0];
                $grouped[$label]['sales']+=$r['sales'];
                $grouped[$label]['cost']+=$r['cost'];
            }
            $performance=array_values($grouped);
        }
        $exposure=$rows('SELECT currency,SUM(native_minor) AS native,SUM(base_minor) AS base FROM ledger WHERE branch_id=? AND day<=? AND instr(account,\':\')>0 GROUP BY currency',[$branch,$to]);
        return compact('group','profit','sheet','flows','performance','exposure');
    }

    /** Open customer and supplier items by days past due. Supplier amounts are shown as positive payables. */
    public function aging(int $branch,string $today): array
    {
        $q=$this->db->prepare("SELECT account,currency,due,outstanding_minor FROM open_items WHERE branch_id=? AND outstanding_minor<>0
            AND (account LIKE 'customer:%' OR account LIKE 'supplier:%') ORDER BY account");
        $q->execute([$branch]);
        $now=new \DateTimeImmutable($today);
        $aging=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $gap=(new \DateTimeImmutable($r['due']))->diff($now);
            $days=$gap->invert ? -$gap->days : $gap->days;
            $bucket=match(true) {$days<=0=>0,$days<=30=>1,$days<=60=>2,$days<=90=>3,default=>4};
            $amount=(str_starts_with($r['account'],'supplier:') ? -1 : 1)*(int)$r['outstanding_minor'];
            $aging[$r['account']] ??= ['account'=>$r['account'],'currency'=>$r['currency'],'buckets'=>[0,0,0,0,0],'total'=>0,'unapplied'=>0];
            if ($amount>0) { $aging[$r['account']]['buckets'][$bucket]+=$amount; } else { $aging[$r['account']]['unapplied']-=$amount; }
            $aging[$r['account']]['total']+=$amount;
        }
        // Payments and credits not yet matched settle the oldest amounts first; any excess shows as a credit in the first column.
        foreach ($aging as &$row) {
            for ($i=4; $i>=0 && $row['unapplied']>0; $i--) {
                $used=min($row['buckets'][$i],$row['unapplied']);
                $row['buckets'][$i]-=$used;
                $row['unapplied']-=$used;
            }
            $row['buckets'][0]-=$row['unapplied'];
            unset($row['unapplied']);
        } unset($row);
        return array_values($aging);
    }
}
