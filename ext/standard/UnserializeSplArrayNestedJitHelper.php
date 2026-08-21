<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin-standalone NestedJIT unserialize() for SPL ArrayObject family (#33636).
 *
 * Own TU with a single public method — NestedJIT mis-types extra methods in the same file (#27030).
 * Prefer helper-runtime cache (do not force PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33625.
 *
 * `$skip` = strlen(`O:len:"Class":n:{`) from LLVM. Digit loops use ord()+break (NestedJIT
 * `$payload[$pos] >= '0'` does not match here — peer UnserializeObjectNestedJitHelper works
 * for `s:` paths that skip empty digit runs).
 *
 * php-src: ext/spl/spl_array.c — ArrayObject::__unserialize integer-keyed bag.
 */
final class UnserializeSplArrayNestedJitHelper
{
    /**
     * Fill `$htPtr` from bag slot 1; return flags (slot 0) or -1 on failure.
     *
     * @param mixed $payload
     */
    public static function restoreInto(int $htPtr, $payload, int $skip): int
    {
        if ($htPtr <= 0) {
            return -1;
        }
        if (null === $payload) {
            return -1;
        }
        if ($skip < 0) {
            return -1;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($skip >= $len) {
            return -1;
        }
        $pos = $skip;
        // i:0;i:FLAGS;
        if ($pos + 3 >= $len) {
            return -1;
        }
        if ('i' !== $payload[$pos]) {
            return -1;
        }
        if (':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        if (48 !== \ord($payload[$pos])) {
            return -1;
        }
        if (';' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        if ($pos + 1 >= $len) {
            return -1;
        }
        if ('i' !== $payload[$pos]) {
            return -1;
        }
        if (':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        $flags = 0;
        $negF = 0;
        if ($pos < $len && '-' === $payload[$pos]) {
            $negF = 1;
            $pos = $pos + 1;
        }
        $guard = 0;
        while ($pos < $len && $guard < 32) {
            $o = \ord($payload[$pos]);
            if ($o < 48) {
                break;
            }
            if ($o > 57) {
                break;
            }
            $flags = $flags * 10 + ($o - 48);
            $pos = $pos + 1;
            $guard = $guard + 1;
        }
        if (1 === $negF) {
            $flags = 0 - $flags;
        }
        if ($pos >= $len) {
            return -1;
        }
        if (';' !== $payload[$pos]) {
            return -1;
        }
        $pos = $pos + 1;
        // i:1;a:N:{…}
        if ($pos + 3 >= $len) {
            return -1;
        }
        if ('i' !== $payload[$pos]) {
            return -1;
        }
        if (':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        if (49 !== \ord($payload[$pos])) {
            return -1;
        }
        if (';' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        if ($pos + 1 >= $len) {
            return -1;
        }
        if ('a' !== $payload[$pos]) {
            return -1;
        }
        if (':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        $n = 0;
        $guard = 0;
        while ($pos < $len && $guard < 32) {
            $o = \ord($payload[$pos]);
            if ($o < 48) {
                break;
            }
            if ($o > 57) {
                break;
            }
            $n = $n * 10 + ($o - 48);
            $pos = $pos + 1;
            $guard = $guard + 1;
        }
        if ($pos >= $len) {
            return -1;
        }
        if (':' !== $payload[$pos]) {
            return -1;
        }
        if ('{' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos = $pos + 2;
        // Empty storage — done.
        if (0 === $n) {
            return $flags;
        }
        $i = 0;
        while ($i < $n && $pos < $len) {
            $keyIsStr = 0;
            $keyLong = 0;
            $keyStr = '';
            if ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $negK = 0;
                if ($pos < $len && '-' === $payload[$pos]) {
                    $negK = 1;
                    $pos = $pos + 1;
                }
                $guard = 0;
                while ($pos < $len && $guard < 32) {
                    $o = \ord($payload[$pos]);
                    if ($o < 48) {
                        break;
                    }
                    if ($o > 57) {
                        break;
                    }
                    $keyLong = $keyLong * 10 + ($o - 48);
                    $pos = $pos + 1;
                    $guard = $guard + 1;
                }
                if (1 === $negK) {
                    $keyLong = 0 - $keyLong;
                }
                if ($pos >= $len || ';' !== $payload[$pos]) {
                    return -1;
                }
                $pos = $pos + 1;
            } elseif ('s' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $keyIsStr = 1;
                $pos = $pos + 2;
                $klen = 0;
                $guard = 0;
                while ($pos < $len && $guard < 32) {
                    $o = \ord($payload[$pos]);
                    if ($o < 48) {
                        break;
                    }
                    if ($o > 57) {
                        break;
                    }
                    $klen = $klen * 10 + ($o - 48);
                    $pos = $pos + 1;
                    $guard = $guard + 1;
                }
                if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                    return -1;
                }
                $pos = $pos + 2;
                $ki = 0;
                while ($ki < $klen && $pos < $len) {
                    $keyStr .= $payload[$pos];
                    $pos = $pos + 1;
                    $ki = $ki + 1;
                }
                if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                    return -1;
                }
                $pos = $pos + 2;
            } else {
                return -1;
            }
            if ($pos >= $len) {
                return -1;
            }
            if ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $negV = 0;
                if ($pos < $len && '-' === $payload[$pos]) {
                    $negV = 1;
                    $pos = $pos + 1;
                }
                $val = 0;
                $guard = 0;
                while ($pos < $len && $guard < 32) {
                    $o = \ord($payload[$pos]);
                    if ($o < 48) {
                        break;
                    }
                    if ($o > 57) {
                        break;
                    }
                    $val = $val * 10 + ($o - 48);
                    $pos = $pos + 1;
                    $guard = $guard + 1;
                }
                if (1 === $negV) {
                    $val = 0 - $val;
                }
                if ($pos >= $len || ';' !== $payload[$pos]) {
                    return -1;
                }
                $pos = $pos + 1;
                if (1 === $keyIsStr) {
                    phpc_native_ht_set_string_key_long($htPtr, $keyStr, $val);
                } else {
                    phpc_native_ht_set_long_at($htPtr, $keyLong, $val);
                }
            } elseif ('s' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $vlen = 0;
                $guard = 0;
                while ($pos < $len && $guard < 32) {
                    $o = \ord($payload[$pos]);
                    if ($o < 48) {
                        break;
                    }
                    if ($o > 57) {
                        break;
                    }
                    $vlen = $vlen * 10 + ($o - 48);
                    $pos = $pos + 1;
                    $guard = $guard + 1;
                }
                if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                    return -1;
                }
                $pos = $pos + 2;
                $vs = '';
                $vi = 0;
                while ($vi < $vlen && $pos < $len) {
                    $vs .= $payload[$pos];
                    $pos = $pos + 1;
                    $vi = $vi + 1;
                }
                if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                    return -1;
                }
                $pos = $pos + 2;
                if (1 === $keyIsStr) {
                    phpc_native_ht_set_string_key($htPtr, $keyStr, $vs);
                } else {
                    phpc_native_ht_set_string_at($htPtr, $keyLong, $vs);
                }
            } elseif ('b' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $b = 0;
                if ($pos < $len && '1' === $payload[$pos]) {
                    $b = 1;
                }
                $pos = $pos + 1;
                if ($pos >= $len || ';' !== $payload[$pos]) {
                    return -1;
                }
                $pos = $pos + 1;
                if (1 === $keyIsStr) {
                    phpc_native_ht_set_string_key_long($htPtr, $keyStr, $b);
                } else {
                    phpc_native_ht_set_long_at($htPtr, $keyLong, $b);
                }
            } elseif ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                $pos = $pos + 2;
            } else {
                return -1;
            }
            $i = $i + 1;
        }
        if ($i !== $n) {
            return -1;
        }

        return $flags;
    }
}
