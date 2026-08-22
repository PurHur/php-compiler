<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * NestedJIT: fill `__spl_ht` from bag storage at `i:1;a:` offset (#33636 / #33663 / #33670 / #33681 / #33686).
 *
 * Own TU / single method — string-key bags with int / string / float / bool / null /
 * one-level nested packed int-key arrays (`a:N:{i:…;i:…;}` via ht_alloc + set_string_key_ht)
 * and nested objects (`O:…` via unserialize + set_string_key_object) (#33681 / #33686).
 * `$pos` must point at the `i` of `i:1;a:`.
 * Packed int-key bags stay in {@see UnserializeSplArrayFillIntKeyNestedJitHelper}.
 *
 * Float values are passed as serialized text (`d:1.5`) so NestedJIT avoids float locals;
 * the native bridge strtod(3)s into `__hashtable__setStringKeyDouble`.
 */
final class UnserializeSplArrayFillNestedJitHelper
{
    public static function fillAt(int $htPtr, string $payload, int $pos): int
    {
        if ($htPtr <= 0 || $pos < 0) {
            return 0;
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        // Skip i:1;a:
        $pos = $pos + 6;
        $count = 0;
        $saw = false;
        $cg = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9' && $cg < 20) {
            ++$cg;
            $saw = true;
            $count = $count * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if (!$saw || $pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        $n = 0;
        while ($n < $count && $n < 64 && $pos < $len) {
            if ('s' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            $klen = 0;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                $klen = $klen * 10 + (\ord($payload[$pos]) - 48);
                $pos = $pos + 1;
            }
            if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            $key = '';
            $ki = 0;
            while ($ki < $klen && $pos < $len) {
                $key .= $payload[$pos];
                $pos = $pos + 1;
                $ki = $ki + 1;
            }
            if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos = $pos + 2;
            if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                phpc_native_ht_set_string_key_null($htPtr, $key);
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
                phpc_native_ht_set_string_key_long($htPtr, $key, $num);
            } elseif ('d' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $dstr = '';
                if ($pos < $len && ('-' === $payload[$pos] || '+' === $payload[$pos])) {
                    $dstr .= $payload[$pos];
                    $pos = $pos + 1;
                }
                $sawD = false;
                while ($pos < $len && (($payload[$pos] >= '0' && $payload[$pos] <= '9') || '.' === $payload[$pos])) {
                    $sawD = true;
                    $dstr .= $payload[$pos];
                    $pos = $pos + 1;
                }
                if (!$sawD || $pos >= $len || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                phpc_native_ht_set_string_key_double($htPtr, $key, $dstr);
            } elseif ('b' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                if ($pos >= $len || ('0' !== $payload[$pos] && '1' !== $payload[$pos])) {
                    return 0;
                }
                $b = ('1' === $payload[$pos]) ? 1 : 0;
                $pos = $pos + 1;
                if ($pos >= $len || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                phpc_native_ht_set_string_key_bool($htPtr, $key, $b);
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
                phpc_native_ht_set_string_key($htPtr, $key, $str);
            } elseif ('a' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                // Nested array value (#33681): a:N:{i:K;i:V;…} — one level, packed int keys.
                $pos = $pos + 2;
                $acount = 0;
                $sawA = false;
                $ag = 0;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9' && $ag < 20) {
                    ++$ag;
                    $sawA = true;
                    $acount = $acount * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawA || $pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                $child = phpc_native_ht_alloc();
                $an = 0;
                while ($an < $acount && $an < 64 && $pos < $len) {
                    if ('i' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                        return 0;
                    }
                    $pos = $pos + 2;
                    $idx = 0;
                    $sawI = false;
                    while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                        $sawI = true;
                        $idx = $idx * 10 + (\ord($payload[$pos]) - 48);
                        $pos = $pos + 1;
                    }
                    if (!$sawI || $pos >= $len || ';' !== $payload[$pos]) {
                        return 0;
                    }
                    $pos = $pos + 1;
                    if ('i' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                        return 0;
                    }
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
                    phpc_native_ht_set_long_at($child, $idx, $num);
                    ++$an;
                }
                if ($pos >= $len || '}' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                phpc_native_ht_set_string_key_ht($htPtr, $key, $child);
            } elseif ('O' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                // Nested stdClass (#33686): parse O:8:"stdClass":N:{s:K;scalar;…} into a
                // props HT then materialize via set_string_key_stdclass_from_ht (AOT
                // __compiler_unserialize does not restore stdClass string props).
                $pos = $pos + 2;
                $clen = 0;
                $sawC = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $sawC = true;
                    $clen = $clen * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawC || $pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                $cname = '';
                $ci = 0;
                while ($ci < $clen && $pos < $len) {
                    $cname .= $payload[$pos];
                    $pos = $pos + 1;
                    $ci = $ci + 1;
                }
                if ($pos + 1 >= $len || '"' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                if ('stdClass' !== $cname) {
                    return 0;
                }
                $ocount = 0;
                $sawO = false;
                $og = 0;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9' && $og < 20) {
                    ++$og;
                    $sawO = true;
                    $ocount = $ocount * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$sawO || $pos + 1 >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
                    return 0;
                }
                $pos = $pos + 2;
                $child = phpc_native_ht_alloc();
                $on = 0;
                while ($on < $ocount && $on < 64 && $pos < $len) {
                    if ('s' !== $payload[$pos] || $pos + 1 >= $len || ':' !== $payload[$pos + 1]) {
                        return 0;
                    }
                    $pos = $pos + 2;
                    $pklen = 0;
                    while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                        $pklen = $pklen * 10 + (\ord($payload[$pos]) - 48);
                        $pos = $pos + 1;
                    }
                    if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                        return 0;
                    }
                    $pos = $pos + 2;
                    $pkey = '';
                    $pki = 0;
                    while ($pki < $pklen && $pos < $len) {
                        $pkey .= $payload[$pos];
                        $pos = $pos + 1;
                        $pki = $pki + 1;
                    }
                    if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                        return 0;
                    }
                    $pos = $pos + 2;
                    if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                        $pos = $pos + 2;
                        phpc_native_ht_set_string_key_null($child, $pkey);
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
                        phpc_native_ht_set_string_key_long($child, $pkey, $num);
                    } elseif ('d' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                        $pos = $pos + 2;
                        $dstr = '';
                        if ($pos < $len && ('-' === $payload[$pos] || '+' === $payload[$pos])) {
                            $dstr .= $payload[$pos];
                            $pos = $pos + 1;
                        }
                        $sawD = false;
                        while ($pos < $len && (($payload[$pos] >= '0' && $payload[$pos] <= '9') || '.' === $payload[$pos])) {
                            $sawD = true;
                            $dstr .= $payload[$pos];
                            $pos = $pos + 1;
                        }
                        if (!$sawD || $pos >= $len || ';' !== $payload[$pos]) {
                            return 0;
                        }
                        $pos = $pos + 1;
                        phpc_native_ht_set_string_key_double($child, $pkey, $dstr);
                    } elseif ('b' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                        $pos = $pos + 2;
                        if ($pos >= $len || ('0' !== $payload[$pos] && '1' !== $payload[$pos])) {
                            return 0;
                        }
                        $b = ('1' === $payload[$pos]) ? 1 : 0;
                        $pos = $pos + 1;
                        if ($pos >= $len || ';' !== $payload[$pos]) {
                            return 0;
                        }
                        $pos = $pos + 1;
                        phpc_native_ht_set_string_key_bool($child, $pkey, $b);
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
                        phpc_native_ht_set_string_key($child, $pkey, $str);
                    } else {
                        return 0;
                    }
                    ++$on;
                }
                if ($pos >= $len || '}' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                phpc_native_ht_set_string_key_stdclass_from_ht($htPtr, $key, $child);
            } else {
                return 0;
            }
            ++$n;
        }

        return 1;
    }
}
