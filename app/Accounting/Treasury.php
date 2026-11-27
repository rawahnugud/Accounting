<?php
declare(strict_types=1);

namespace App\Accounting;

use DomainException;
use InvalidArgumentException;
use PDO;

final class Treasury
{
    public function __construct(private PDO $db)
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA recursive_triggers = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
        if (!(int) $db->query('PRAGMA foreign_keys')->fetchColumn()) {
            throw new DomainException('Enable SQLite foreign keys before starting a transaction.');
        }
    }

    /** Called by a trusted adapter after authentication and branch authorization. */
    public function transfer(int $company, int $branch, int $source, int $destination,
        string $currency, string $amount, string $key, string $actor): int
    {
        return $this->post($company, $branch, $source, $destination, $currency, $amount, $key, $actor, null);
    }

    public function transferForStaff(int $userId, int $company, int $branch, int $source,
        int $destination, string $currency, string $amount, string $key): int
    {
        return $this->post($company, $branch, $source, $destination, $currency, $amount, $key,
            'staff:'.$userId, $userId);
    }

    private function post(int $company, int $branch, int $source, int $destination,
        string $currency, string $amount, string $key, string $actor, ?int $userId): int
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($userId !== null) {
                $allowed = $this->db->prepare("SELECT 1 FROM branch_staff
                    WHERE user_id = ? AND branch_id = ? AND company_id = ? AND role = 'cashier'");
                $allowed->execute([$userId, $branch, $company]);
                if (!$allowed->fetchColumn()) {
                    throw new BranchAccessDenied('Transfer permission required.');
                }
            }
            $minor = $this->minorUnits($currency, $amount);
            $id = $this->record([$company, $branch, $source, $destination, $currency, $minor, $key, $actor]);
            $this->db->exec('COMMIT');
            return $id;
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    private function minorUnits(string $currency, string $amount): int
    {
        $precision = $this->db->prepare('SELECT decimals FROM currencies WHERE code = ?');
        $precision->execute([$currency]);
        $decimals = $precision->fetchColumn();
        $precision->closeCursor();
        if ($decimals === false || !preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/', $amount, $match)) {
            throw new InvalidArgumentException('Enter a known currency and a positive decimal amount.');
        }
        $fraction = $match[2] ?? '';
        if (strlen($fraction) > (int) $decimals) {
            throw new InvalidArgumentException('Too many fractional digits for this currency.');
        }
        $digits = ltrim($match[1] . str_pad($fraction, (int) $decimals, '0'), '0');
        if ($digits === '') {
            throw new InvalidArgumentException('Enter an amount greater than zero.');
        }
        if ((string)(int)$digits !== $digits) {
            throw new InvalidArgumentException('Amount outside the supported range.');
        }
        return (int)$digits;
    }

    private function record(array $values): int
    {
        $insert = $this->db->prepare('INSERT INTO treasury_transfers
            (company_id, branch_id, source_id, destination_id, currency, amount_minor, request_key, actor)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(company_id, request_key) DO NOTHING');
        $insert->execute($values);

        // Both a fresh submission and an identical retry resolve to the same immutable row.
        $find = $this->db->prepare('SELECT id FROM treasury_transfers WHERE company_id = ?
            AND branch_id = ? AND source_id = ? AND destination_id = ? AND currency = ?
            AND amount_minor = ? AND request_key = ? AND actor = ?');
        $find->execute($values);
        $id = $find->fetchColumn();
        if ($id === false) {
            throw new DomainException('Request key already used with different transfer details.');
        }
        return (int)$id;
    }
}
