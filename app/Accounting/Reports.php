<?php
declare(strict_types=1);
namespace App\Accounting;

use PDO;

final class Reports
{
    public function __construct(private PDO $db) {}

    /**
     * Period performance and cash flows use movements. The balance sheet is cumulative through the end date.
     * $value(currency,native,base) gives the amount shown in the chosen total currency; by default the booked reporting amount.
     * $account limits profit, profitability and cash movements to documents that touch that account (for example customer:4).
     */
    public function statements(int $branch,string $from,string $to,string $group,?callable $value=null,string $account=''): array
    {
        $value ??= static fn(string $currency,int $native,int $base):int=>$base;
        $only=$account==='' ? '' : ' AND number IN (SELECT \'D-\'||document_id FROM entries WHERE account=?)';
        $onlyDoc=$account==='' ? '' : ' AND d.id IN (SELECT document_id FROM entries WHERE account=?)';
        $extra=$account==='' ? [] : [$account];
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
        // Every figure is kept twice: per currency in native amounts (dual), and as the total in the chosen currency.
        $dual=['profit'=>[],'sheet'=>[],'flows'=>[]];
        $currencies=[];
        $add=function(string $part,string $label,string $currency,int $native) use (&$dual,&$currencies):void {
            $currencies[$currency]=true;
            $dual[$part][$label][$currency]=($dual[$part][$label][$currency] ?? 0)+$native;
        };
        $profit=[];
        foreach ($rows("SELECT account,currency,SUM(native_minor) AS native,SUM(base_minor) AS amount FROM ledger WHERE branch_id=? AND day BETWEEN ? AND ?
            AND account IN ('sales','service_cost','salary_expense','fx')$only GROUP BY account,currency",[$branch,$from,$to,...$extra]) as $r) {
            $profit[$r['account']]=($profit[$r['account']] ?? 0)-$value($r['currency'],(int)$r['native'],(int)$r['amount']);
            $add('profit',$r['account'],$r['currency'],-(int)$r['native']);
        }
        // Split other expenses by the category saved on fee and creditor obligation documents; a reversal uses its original's category.
        foreach ($rows("SELECT COALESCE(o.details,d.details) AS details,e.currency,SUM(e.native_minor) AS native,SUM(e.base_minor) AS amount FROM entries e JOIN documents d ON d.id=e.document_id
            LEFT JOIN documents o ON o.id=d.reversal_of WHERE d.branch_id=? AND d.day BETWEEN ? AND ? AND e.account='expense'$onlyDoc GROUP BY 1,2",[$branch,$from,$to,...$extra]) as $r) {
            $label=(json_decode($r['details'],true)['category'] ?? '') ?: 'expense';
            $profit[$label]=($profit[$label] ?? 0)-$value($r['currency'],(int)$r['native'],(int)$r['amount']);
            $add('profit',$label,$r['currency'],-(int)$r['native']);
        }
        $balances=$rows("SELECT account,currency,SUM(native_minor) AS native,SUM(base_minor) AS amount FROM ledger WHERE branch_id=? AND day<=? GROUP BY account,currency",[$branch,$to]);
        $sheet=['Cash'=>0,'Receivables'=>0,'Staff advances'=>0,'Supplier prepayments'=>0,'Customer deposits'=>0,
            'Supplier payables'=>0,'Creditor obligations'=>0,'Payroll payable'=>0,'Owner equity'=>0,'Accumulated earnings'=>0];
        foreach ($balances as $r) {
            $native=(int)$r['native']; $amount=$value($r['currency'],$native,(int)$r['amount']);
            [$label,$sign]=match(explode(':',$r['account'])[0]) {
                'cash'=>['Cash',1], 'advance'=>['Staff advances',1],
                'customer'=>$amount>=0?['Receivables',1]:['Customer deposits',-1],
                'supplier'=>$amount>=0?['Supplier prepayments',1]:['Supplier payables',-1],
                'employee'=>['Payroll payable',-1], 'creditor'=>['Creditor obligations',-1], 'equity'=>['Owner equity',-1],
                default=>['Accumulated earnings',-1]
            };
            $sheet[$label]+=$sign*$amount;
            if ($native) { $add('sheet',$label,$r['currency'],$sign*$native); }
        }
        $assets=$sheet['Cash']+$sheet['Receivables']+$sheet['Staff advances']+$sheet['Supplier prepayments'];
        $claims=array_sum(array_slice($sheet,4));
        $sheet=array_slice($sheet,0,4)+['Total assets'=>$assets]+array_slice($sheet,4)+['Total liabilities and equity'=>$claims];
        if ($assets!==$claims) { $sheet['Unbalanced difference']=$assets-$claims; }
        $flows=[];
        foreach ($rows("SELECT kind,currency,SUM(native_minor) AS native,SUM(base_minor) AS amount FROM ledger WHERE branch_id=? AND day BETWEEN ? AND ? AND account LIKE 'cash:%'$only GROUP BY kind,currency",[$branch,$from,$to,...$extra]) as $r) {
            $flows[$r['kind']]=($flows[$r['kind']] ?? 0)+$value($r['currency'],(int)$r['native'],(int)$r['amount']);
            $add('flows',$r['kind'],$r['currency'],(int)$r['native']);
        }
        $currencies=array_keys($currencies);
        sort($currencies);
        $grouped=[];
        foreach ($rows("SELECT COALESCE(NULLIF($dimension,''),'Unspecified') AS label,e.account,e.currency,SUM(e.native_minor) AS native,SUM(e.base_minor) AS base
            FROM documents d JOIN entries e ON e.document_id=d.id JOIN users u ON u.id=d.user_id
            LEFT JOIN documents original ON original.id=d.reversal_of
            WHERE d.branch_id=? AND d.day BETWEEN ? AND ? AND e.account IN ('sales','service_cost')$onlyDoc GROUP BY label,e.account,e.currency",[$branch,$from,$to,...$extra]) as $r) {
            $label=in_array($group,['service','airline'],true) ? ((json_decode($r['label'],true)[$group] ?? '') ?: 'Unspecified') : $r['label'];
            $grouped[$label] ??= ['label'=>$label,'sales'=>0,'cost'=>0];
            $v=$value($r['currency'],(int)$r['native'],(int)$r['base']);
            if ($r['account']==='sales') { $grouped[$label]['sales']-=$v; } else { $grouped[$label]['cost']+=$v; }
        }
        ksort($grouped);
        $performance=array_values($grouped);
        $exposure=$rows('SELECT currency,SUM(native_minor) AS native,SUM(base_minor) AS base FROM ledger WHERE branch_id=? AND day<=? AND instr(account,\':\')>0 GROUP BY currency',[$branch,$to]);
        foreach ($exposure as &$r) { $r['base']=$value($r['currency'],(int)$r['native'],(int)$r['base']); } unset($r);
        return compact('group','profit','sheet','flows','performance','exposure','dual','currencies');
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
