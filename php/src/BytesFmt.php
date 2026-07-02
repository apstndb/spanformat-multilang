<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

final class BytesFmt
{
    public static function decodeBase64Wire(string $wire): string
    {
        if ($wire === '') {
            return '';
        }
        $decoded = base64_decode($wire, true);
        if ($decoded === false) {
            throw new MalformedWireError("invalid base64 wire: {$wire}");
        }
        return $decoded;
    }

    private static function isReadableAscii(string $data): bool
    {
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($data[$i]);
            if ($c === ord('\\') || $c < 0x20 || $c > 0x7E) {
                return false;
            }
        }
        return true;
    }

    public static function readableBytesString(string $data): string
    {
        if ($data === '') {
            return '';
        }
        if (self::isReadableAscii($data)) {
            return $data;
        }
        $parts = [];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $parts[] = Quote::escapeRune(ord($data[$i]), false, '');
        }
        return implode('', $parts);
    }

    public static function readableStringFromBase64Wire(string $wire): string
    {
        return self::readableBytesString(self::decodeBase64Wire($wire));
    }
}
