<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: restore SplObjectStorage `i:0;a:N:{obj,info,…}` into `__spl_ht` (#33876).
 *
 * Own TU / single method — NestedJIT blanks large bodies.
 * Object keys: empty stdClass (`O:8:"stdClass":0:{}`) via attach natives (peer #33686).
 * Info: null / long / string. Other object shapes need a follow-up.
 * Object keys use disableRefcount in SosAttachNativeOpsJit so NestedJIT temps cannot free them (#33876).
 * php-src: ext/spl/spl_observer.c — SplObjectStorage::__unserialize
 */
final class UnserializeSplObjectStorageNestedJitHelper
{
    /**
     * Fill caller-owned object-key HT from full O: payload. Returns 1 on success.
     *
     * @param mixed $payload
     */
    public static function restoreInto(int $htPtr, string $payload): int
    {
        if ($htPtr <= 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($len < 5 || 'O' !== $payload[0] || ':' !== $payload[1]) {
            return 0;
        }
        // Skip to body `{` after O:len:"Class":2:
        $pos = 2;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $pos = $pos + 1;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        while ($pos < $len && '"' !== $payload[$pos]) {
            $pos = $pos + 1;
        }
        if ($pos >= $len || '"' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return 0;
        }
        $pos = $pos + 1;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $pos = $pos + 1;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        // Expect i:0;a:N:{
        if ($pos + 6 >= $len
            || 'i' !== $payload[$pos]
            || ':' !== $payload[$pos + 1]
            || '0' !== $payload[$pos + 2]
            || ';' !== $payload[$pos + 3]
            || 'a' !== $payload[$pos + 4]
            || ':' !== $payload[$pos + 5]
        ) {
            return 0;
        }
        $pos = $pos + 6;
        $count = 0;
        $saw = false;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $saw = true;
            $count = $count * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$saw || $pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        if (0 !== ($count % 2)) {
            return 0;
        }
        $n = 0;
        while ($n < $count && $n < 128 && $pos < $len) {
            // Skip i:k;
            if ('i' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            while ($pos < $len && (($payload[$pos] >= '0' && $payload[$pos] <= '9') || '-' === $payload[$pos])) {
                $pos = $pos + 1;
            }
            if ($pos >= $len || ';' !== $payload[$pos]) {
                return 0;
            }
            $pos = $pos + 1;
            $isObjSlot = (0 === ($n % 2));
            if ($isObjSlot) {
                // Only empty stdClass for now (#33876 repro).
                if ($pos + 20 > $len
                    || 'O' !== $payload[$pos]
                    || ':' !== $payload[$pos + 1]
                ) {
                    return 0;
                }
                $marker = 'O:8:"stdClass":0:{}';
                $ml = \strlen($marker);
                $ok = true;
                $mi = 0;
                while ($mi < $ml) {
                    if ($pos + $mi >= $len || $payload[$pos + $mi] !== $marker[$mi]) {
                        $ok = false;
                        break;
                    }
                    $mi = $mi + 1;
                }
                if (!$ok) {
                    return 0;
                }
                $pos = $pos + $ml;
                // Peek next pair's value after skipping its i:k; — handled on next odd iteration.
            } else {
                // Info for previous empty stdClass.
                if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                    $pos = $pos + 2;
                    phpc_native_sos_attach_empty_stdclass_null($htPtr);
                } elseif ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                    $pos = $pos + 2;
                    $num = 0;
                    $vneg = false;
                    if ($pos < $len && '-' === $payload[$pos]) {
                        $vneg = true;
                        $pos = $pos + 1;
                    }
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
                    if ($vneg) {
                        $num = 0 - $num;
                    }
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
            }
            ++$n;
        }

        return 1;
    }
}
