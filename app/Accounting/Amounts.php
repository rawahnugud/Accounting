<?php
declare(strict_types=1);
namespace App\Accounting;

use DomainException;

final class Amounts
{
    /** Accept what staff type: Arabic-Indic digits, Arabic separators, and thousands commas or spaces. */
    public static function clean(string $value): string
    {
        return strtr(trim($value), ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٫'=>'.','٬'=>'',','=>'',' '=>'',"\u{A0}"=>'']);
    }

    public static function parse(string $value, int $precision): int
    {
        $value = self::clean($value);
        if (!preg_match('/\A(\d{1,15})(?:\.(\d+))?\z/D', $value, $m)) {
            throw new DomainException('Check the amount and its decimal places.');
        }
        if (strlen($m[2] ?? '') > $precision) {
            throw new DomainException("Use at most $precision decimal places.");
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

    /** For screens and printouts only: thousands separators. Forms and exports keep format(). */
    public static function display(int $minor, int $precision): string
    {
        [$whole,$fraction]=array_pad(explode('.',self::format(abs($minor),$precision)),2,null);
        return ($minor < 0 ? '-' : '').strrev(implode(',',str_split(strrev($whole),3))).($fraction===null ? '' : '.'.$fraction);
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
