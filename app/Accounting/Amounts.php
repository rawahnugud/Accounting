<?php
declare(strict_types=1);
namespace App\Accounting;

use DomainException;

final class Amounts
{
    public static function parse(string $value, int $precision): int
    {
        if (!preg_match('/\A(\d{1,15})(?:\.(\d+))?\z/D', $value, $m) || strlen($m[2] ?? '') > $precision) {
            throw new DomainException('Check the amount and its decimal places.');
        }
        $minor = (int)($m[1].str_pad($m[2] ?? '', $precision, '0'));
        if ($minor > 999999999999999) {
            throw new DomainException('Amount exceeds the supported range.');
        }
        return $minor;
    }

    public static function format(int $minor, int $precision): string
    {
        $digits = str_pad((string)abs($minor), $precision+1, '0', STR_PAD_LEFT);
        return ($minor < 0 ? '-' : '').($precision ? substr($digits,0,-$precision).'.'.substr($digits,-$precision) : $digits);
    }

    // Positive integer a*b/d, rounded half up. Reduce each doubled remainder before it can overflow.
    public static function ratio(int $a, int $b, int $d): int
    {
        if ($d <= 0) { throw new DomainException('Exchange rate must be positive.'); }
        $whole = 0;
        $rest = 0;
        foreach (str_split(decbin($b)) as $bit) {
            $rest = 2*$rest + ($bit === '1' ? $a % $d : 0);
            $whole = 2*$whole + ($bit === '1' ? intdiv($a,$d) : 0) + intdiv($rest,$d);
            if ($whole > 999999999999999) { throw new DomainException('Converted amount is too large.'); }
            $rest %= $d;
        }
        return $whole + (int)(2*$rest >= $d);
    }
}
