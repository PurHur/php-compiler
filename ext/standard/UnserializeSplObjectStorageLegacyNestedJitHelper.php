<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT legacy Serializable::unserialize() for SplObjectStorage (#35117).
 *
 * Own TU / keep tiny — NestedJIT blanks large bodies. Wire: `x:i:N;obj,info;…m:…`
 * (php-src zim_SplObjectStorage_unserialize). Empty stdClass + null/long/string info.
 */
final class UnserializeSplObjectStorageLegacyNestedJitHelper
{
    public static function restoreInto(int $htPtr, string $payload): int
    {
        if ($htPtr <= 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($len < 8 || 'x' !== $payload[0] || ':' !== $payload[1] || 'i' !== $payload[2] || ':' !== $payload[3]) {
            return 0;
        }
        $pos = 4;
        $count = 0;
        $saw = false;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $saw = true;
            $count = $count * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$saw || $pos >= $len || ';' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        $marker = 'O:8:"stdClass":0:{}';
        $ml = \strlen($marker);
        $i = 0;
        while ($i < $count && $i < 64 && $pos + $ml <= $len) {
            $mi = 0;
            $ok = true;
            while ($mi < $ml) {
                if ($payload[$pos + $mi] !== $marker[$mi]) {
                    $ok = false;
                    break;
                }
                $mi = $mi + 1;
            }
            if (!$ok || ',' !== $payload[$pos + $ml]) {
                return 0;
            }
            $pos = $pos + $ml + 1;
            if ('N' === $payload[$pos] && ';' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                phpc_native_sos_attach_empty_stdclass_null($htPtr);
            } elseif ('i' === $payload[$pos] && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $num = 0;
                $neg = false;
                if ('-' === $payload[$pos]) {
                    $neg = true;
                    $pos = $pos + 1;
                }
                $sawN = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawN = true;
                    $num = $num * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawN || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                if ($neg) {
                    $num = 0 - $num;
                }
                phpc_native_sos_attach_empty_stdclass_long($htPtr, $num);
            } elseif ('s' === $payload[$pos] && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $slen = 0;
                $sawS = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawS = true;
                    $slen = $slen * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawS || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                $str = '';
                $si = 0;
                while ($si < $slen && $pos < $len) {
                    $str .= $payload[$pos];
                    $pos = $pos + 1;
                    $si = $si + 1;
                }
                if ('"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                phpc_native_sos_attach_empty_stdclass_string($htPtr, $str);
            } else {
                return 0;
            }
            if (';' !== $payload[$pos]) {
                return 0;
            }
            $pos = $pos + 1;
            $i = $i + 1;
        }
        if ($pos >= $len || 'm' !== $payload[$pos]) {
            return 0;
        }

        return 1;
    }
}
