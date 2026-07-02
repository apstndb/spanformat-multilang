<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

enum QuoteStrategy: int
{
    case LEGACY = 0;
    case ALWAYS = 1;
    case MIN_ESCAPE = 2;
}

enum PreferredQuote: int
{
    case DOUBLE = 0;
    case SINGLE = 1;
}

final readonly class LiteralQuoteConfig
{
    public function __construct(
        public QuoteStrategy $strategy = QuoteStrategy::LEGACY,
        public PreferredQuote $preferredQuote = PreferredQuote::DOUBLE,
    ) {
    }
}

final class Quote
{
    public static function normalizeLiteralQuote(LiteralQuoteConfig $cfg): LiteralQuoteConfig
    {
        $strategy = $cfg->strategy;
        if (!in_array($strategy, [QuoteStrategy::LEGACY, QuoteStrategy::ALWAYS, QuoteStrategy::MIN_ESCAPE], true)) {
            $strategy = QuoteStrategy::LEGACY;
        }
        $preferred = $cfg->preferredQuote;
        if (!in_array($preferred, [PreferredQuote::DOUBLE, PreferredQuote::SINGLE], true)) {
            $preferred = PreferredQuote::DOUBLE;
        }
        return new LiteralQuoteConfig($strategy, $preferred);
    }

    public static function quoteCharForPayload(string $payload, LiteralQuoteConfig $cfg): string
    {
        $cfg = self::normalizeLiteralQuote($cfg);
        $data = $payload;

        if ($cfg->strategy === QuoteStrategy::ALWAYS) {
            return $cfg->preferredQuote === PreferredQuote::SINGLE ? "'" : '"';
        }

        $pref = $cfg->preferredQuote === PreferredQuote::SINGLE ? ord("'") : ord('"');
        $other = $pref === ord("'") ? ord('"') : ord("'");

        if ($cfg->strategy === QuoteStrategy::MIN_ESCAPE) {
            $singleCount = substr_count($data, "'");
            $doubleCount = substr_count($data, '"');
            if ($singleCount < $doubleCount) {
                return "'";
            }
            if ($doubleCount < $singleCount) {
                return '"';
            }
            return chr($pref);
        }

        $hasPref = false;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($data[$i]);
            if ($b === $other) {
                return chr($pref);
            }
            if ($b === $pref) {
                $hasPref = true;
            }
        }
        if ($hasPref) {
            return chr($other);
        }
        return chr($pref);
    }

    public static function isPrintable(int $r): bool
    {
        if ($r === 0x20) {
            return true;
        }
        if ($r < 0x20) {
            return false;
        }
        return (bool) preg_match('/^[\p{L}\p{M}\p{N}\p{P}\p{S}]$/u', self::chr($r));
    }

    public static function escapeRune(int $r, bool $isString, string $quote): string
    {
        $q = $quote !== '' ? ord($quote) : -1;
        if ($r === ord('\\') || ($quote !== '' && $r === $q)) {
            return '\\' . chr($r);
        }
        if ($isString && $r === ord("\n")) {
            return '\\n';
        }
        if ($isString && $r === ord("\r")) {
            return '\\r';
        }
        if ($isString && $r === ord("\t")) {
            return '\\t';
        }
        if ($isString && self::isPrintable($r)) {
            return self::chr($r);
        }
        if ($r >= 0x20 && $r <= 0x7E) {
            return chr($r);
        }
        if ($r < 0x100) {
            return sprintf('\\x%02x', $r);
        }
        if ($r > 0xFFFF) {
            return sprintf('\\U%08x', $r);
        }
        return sprintf('\\u%04x', $r);
    }

    private static function chr(int $r): string
    {
        return \IntlChar::chr($r);
    }

    public static function toStringLiteral(string $s, LiteralQuoteConfig $cfg): string
    {
        $quote = self::quoteCharForPayload($s, $cfg);
        $parts = [$quote];
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            $r = mb_ord($ch, 'UTF-8');
            $parts[] = self::escapeRune($r, true, $quote);
        }
        $parts[] = $quote;
        return implode('', $parts);
    }

    public static function toBytesLiteral(string $data, LiteralQuoteConfig $cfg): string
    {
        $quote = self::quoteCharForPayload($data, $cfg);
        $parts = ['b', $quote];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $parts[] = self::escapeRune(ord($data[$i]), false, $quote);
        }
        $parts[] = $quote;
        return implode('', $parts);
    }

    public static function sqlCastQuoted(string $payload, string $castType, LiteralQuoteConfig $cfg): string
    {
        $lit = self::toStringLiteral($payload, $cfg);
        return "CAST({$lit} AS {$castType})";
    }
}
