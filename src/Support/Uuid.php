<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Support;

/**
 * UUID version 7 (RFC 9562): 48-bit Unix epoch milliseconds, then random bits.
 * Time-ordered across calls in different milliseconds; ordering within one millisecond
 * is not guaranteed (the protocol orders entries by (session, seq), not by id).
 */
final class Uuid
{
    public static function v7(): string
    {
        $hex = str_pad(dechex((int) (microtime(true) * 1000)), 12, '0', STR_PAD_LEFT) . bin2hex(random_bytes(10));
        $hex[12] = '7';                                              // version
        $hex[16] = '89ab'[hexdec($hex[16]) & 3];                     // RFC 4122 variant (10xx)

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** W3C trace-id: 16 random bytes as 32 lowercase hex characters. */
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** W3C span-id: 8 random bytes as 16 lowercase hex characters. */
    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
