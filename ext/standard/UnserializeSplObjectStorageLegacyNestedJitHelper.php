<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: restore SplObjectStorage legacy x:/m: (empty stdClass keys) (#35117).
 *
 * Own TU / single method — keep tiny (#27030). Uses sos attach natives (peer #33876).
 */
final class UnserializeSplObjectStorageLegacyNestedJitHelper
{
    /**
     * @param mixed $payload
     */
    public static function restoreInto(int $htPtr, string $payload): int
    {
        if ($htPtr <= 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($len < 5 || 'x' !== $payload[0] || ':' !== $payload[1] || 'i' !== $payload[2] || ':' !== $payload[3]) {
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
        $n = 0;
        while ($n < $count && $n < 32 && $pos + 20 <= $len) {
            // Exact prefix O:8:"stdClass":0:{}, (20 bytes)
            if ('O' !== $payload[$pos]
                || ':' !== $payload[$pos + 1]
                || '8' !== $payload[$pos + 2]
                || ':' !== $payload[$pos + 3]
                || '"' !== $payload[$pos + 4]
                || 's' !== $payload[$pos + 5]
                || 't' !== $payload[$pos + 6]
                || 'd' !== $payload[$pos + 7]
                || 'C' !== $payload[$pos + 8]
                || 'l' !== $payload[$pos + 9]
                || 'a' !== $payload[$pos + 10]
                || 's' !== $payload[$pos + 11]
                || 's' !== $payload[$pos + 12]
                || '"' !== $payload[$pos + 13]
                || ':' !== $payload[$pos + 14]
                || '0' !== $payload[$pos + 15]
                || ':' !== $payload[$pos + 16]
                || '{' !== $payload[$pos + 17]
                || '}' !== $payload[$pos + 18]
                || ',' !== $payload[$pos + 19]
            ) {
                return 0;
            }
            $pos = $pos + 20;
            if ($pos >= $len) {
                return 0;
            }
            if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                phpc_native_sos_attach_empty_stdclass_null($htPtr);
                $pos = $pos + 2;
            } elseif ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $num = 0;
                $sawN = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawN = true;
                    $num = $num * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawN || $pos >= $len || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                phpc_native_sos_attach_empty_stdclass_long($htPtr, $num);
            } elseif ('s' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $slen = 0;
                $sawS = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawS = true;
                    $slen = $slen * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawS || $pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
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
                if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                phpc_native_sos_attach_empty_stdclass_string($htPtr, $str);
            } else {
                return 0;
            }
            if ($pos >= $len || ';' !== $payload[$pos]) {
                return 0;
            }
            $pos = $pos + 1;
            $n = $n + 1;
        }

        return 1;
    }
}
