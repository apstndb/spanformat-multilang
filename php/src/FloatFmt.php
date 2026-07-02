<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

final class FloatFmt
{
    private static function isNegativeZero(float $v): bool
    {
        static $negZeroBytes = null;
        if ($negZeroBytes === null) {
            $negZeroBytes = pack('d', -0.0);
        }
        return pack('d', $v) === $negZeroBytes;
    }

    public static function narrowFloat32(float $v): float
    {
        $packed = pack('g', $v);
        $unpacked = unpack('g', $packed);
        return $unpacked[1];
    }

    private static function packFloat32(float $v): float
    {
        return self::narrowFloat32($v);
    }

    private static function roundTrips(string $s, float $original, int $bits): bool
    {
        if (!is_numeric($s)) {
            return false;
        }
        $parsed = (float) $s;
        if (is_nan($original)) {
            return is_nan($parsed);
        }
        if (is_infinite($original)) {
            return is_infinite($parsed) && ($parsed > 0) === ($original > 0);
        }
        if ($bits === 32) {
            return self::packFloat32($parsed) === self::packFloat32($original);
        }
        return $parsed === $original;
    }

    private static function fmtExponent(int $exp): string
    {
        if ($exp >= 0) {
            return sprintf('e+%02d', $exp);
        }
        $ae = abs($exp);
        if ($ae < 10) {
            return sprintf('e-%02d', $ae);
        }
        return 'e' . $exp;
    }

    private static function peToGoG(string $es): string
    {
        if (!preg_match('/^(\d)(?:\.(\d+))?e([+-])(\d+)$/', $es, $m)) {
            throw new \ValueError("unexpected e-format: {$es}");
        }
        $d1 = $m[1];
        $rest = $m[2] ?? '';
        $exp = (int) ($m[3] . $m[4]);
        $sig = $d1 . rtrim($rest, '0');
        if ($sig === '') {
            $sig = '0';
        }
        $ndigits = strlen($sig);

        if ($exp >= -4 && $exp < 6) {
            $decPos = 1 + $exp;
            if ($decPos <= 0) {
                $s = '0.' . str_repeat('0', -$decPos) . $sig;
            } elseif ($decPos >= $ndigits) {
                $s = $sig . str_repeat('0', $decPos - $ndigits);
            } else {
                $s = substr($sig, 0, $decPos) . '.' . substr($sig, $decPos);
            }
            if (str_contains($s, '.')) {
                $s = rtrim(rtrim($s, '0'), '.');
            }
            return $s;
        }

        if ($ndigits === 1) {
            $body = $sig;
        } else {
            $body = $sig[0] . '.' . substr($sig, 1);
        }
        return $body . self::fmtExponent($exp);
    }

    public static function formatGoG(float $v, int $bits = 64): string
    {
        if ($bits === 32) {
            $v = self::narrowFloat32($v);
        }

        if (is_nan($v)) {
            return 'NaN';
        }
        if (is_infinite($v)) {
            return $v < 0 ? '-Inf' : '+Inf';
        }
        if (self::isNegativeZero($v)) {
            return '-0';
        }

        $negative = $v < 0;
        $av = $negative ? -$v : $v;
        $maxP = $bits === 64 ? 16 : 8;

        $best = null;
        $target = $bits === 64 ? $v : self::narrowFloat32($v);
        for ($p = 0; $p <= $maxP; $p++) {
            $es = sprintf("%.{$p}e", $av);
            $g = self::peToGoG($es);
            $candidate = ($negative ? '-' : '') . $g;
            if (self::roundTrips($candidate, $target, $bits)) {
                if ($best === null || strlen($candidate) < strlen($best)) {
                    $best = $candidate;
                }
            }
        }

        if ($best === null) {
            $best = ($negative ? '-' : '') . (string) $av;
        }
        return $best;
    }

    private static function floatTrunc(float $v): float
    {
        return $v >= 0 ? floor($v) : ceil($v);
    }

    public static function formatSpannerCliFloat(float $v, int $bits = 64): string
    {
        if ($bits === 32) {
            $v = self::narrowFloat32($v);
        }
        if (is_nan($v)) {
            return 'NaN';
        }
        if (is_infinite($v)) {
            return $v < 0 ? '-Inf' : '+Inf';
        }
        if (self::isNegativeZero($v)) {
            return '-0';
        }
        if ($v === self::floatTrunc($v)) {
            return sprintf('%.0f', $v);
        }
        return sprintf('%.6f', $v);
    }

    public static function float64ToLiteral(float $v, LiteralQuoteConfig $quoteCfg): string
    {
        if (is_nan($v)) {
            return Quote::sqlCastQuoted('nan', 'FLOAT64', $quoteCfg);
        }
        if (is_infinite($v)) {
            return Quote::sqlCastQuoted($v < 0 ? '-inf' : 'inf', 'FLOAT64', $quoteCfg);
        }
        $s = self::formatGoG($v, 64);
        if (!strpbrk($s, '.eE')) {
            $s .= '.0';
        }
        return $s;
    }

    public static function float32ToLiteral(float $v, LiteralQuoteConfig $quoteCfg): string
    {
        $fv = self::narrowFloat32($v);
        if (is_nan($fv)) {
            return Quote::sqlCastQuoted('nan', 'FLOAT32', $quoteCfg);
        }
        if (is_infinite($fv)) {
            return Quote::sqlCastQuoted($fv < 0 ? '-inf' : 'inf', 'FLOAT32', $quoteCfg);
        }
        return 'CAST(' . self::formatGoG($fv, 32) . ' AS FLOAT32)';
    }
}
