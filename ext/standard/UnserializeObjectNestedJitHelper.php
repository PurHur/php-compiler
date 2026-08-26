<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin-standalone NestedJIT unserialize() for simple O: public-prop objects (#27030 / #35107).
 *
 * NestedJIT mishandles `$x++` / `$x += n` in this helper — use `$x = $x + n` (#35107).
 * `propsInto` fills a props HT via phpc_native_ht_set_string_key_* (peer ArrayObject #33636).
 * php-src: ext/standard/var_unserializer.c
 */
final class UnserializeObjectNestedJitHelper
{
    public static function isObjectWire(string $payload): int
    {
        $len = \strlen($payload);

        return ($len > 2 && 'O' === $payload[0] && ':' === $payload[1]) ? 1 : 0;
    }

    /**
     * @param mixed $payload
     */
    public static function className($payload): string
    {
        if (null === $payload) {
            return '';
        }
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($len < 5 || 'O' !== $payload[0]) {
            return '';
        }
        $pos = 2;
        $nameLen = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $nameLen = $nameLen * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if ($pos >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return '';
        }
        $pos = $pos + 2;
        $name = '';
        $i = 0;
        while ($i < $nameLen && $pos < $len) {
            $name .= $payload[$pos];
            $pos = $pos + 1;
            $i = $i + 1;
        }

        return $name;
    }

    /** @return int first public int prop value, or 0 */
    public static function firstIntProp(string $payload): int
    {
        $len = \strlen($payload);
        if ($len < 5 || 'O' !== $payload[0]) {
            return 0;
        }
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
        if ($pos >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        if ($pos + 1 >= $len || 's' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
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
        if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        if ($pos >= $len || 'i' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        $neg = false;
        if ($pos < $len && '-' === $payload[$pos]) {
            $neg = true;
            $pos = $pos + 1;
        }
        $num = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $num = $num * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if ($neg) {
            $num = 0 - $num;
        }

        return $num;
    }

    /**
     * Parse public prop pairs into $destPtr HT keyed by property name (#35107).
     *
     * @return int 1 on success
     */
    public static function propsInto(int $destPtr, string $payload): int
    {
        if ($destPtr <= 0) {
            return 0;
        }
        $len = \strlen($payload);
        if ($len < 5 || 'O' !== $payload[0]) {
            return 0;
        }
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
        $propCount = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $propCount = $propCount * 10 + (\ord($payload[$pos]) - 48);
            $pos = $pos + 1;
        }
        if ($pos >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos = $pos + 2;
        $n = 0;
        while ($n < $propCount && $n < 64 && $pos < $len) {
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
            if ('' !== $key && "\0" === $key[0]) {
                return 0;
            }
            if ('N' === $payload[$pos] && $pos + 1 < $len && ';' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                phpc_native_ht_set_string_key_null($destPtr, $key);
            } elseif ('i' === $payload[$pos] && $pos + 1 < $len && ':' === $payload[$pos + 1]) {
                $pos = $pos + 2;
                $neg = false;
                if ($pos < $len && '-' === $payload[$pos]) {
                    $neg = true;
                    $pos = $pos + 1;
                }
                $num = 0;
                $saw = false;
                while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                    $saw = true;
                    $num = $num * 10 + (\ord($payload[$pos]) - 48);
                    $pos = $pos + 1;
                }
                if (!$saw || $pos >= $len || ';' !== $payload[$pos]) {
                    return 0;
                }
                $pos = $pos + 1;
                if ($neg) {
                    $num = 0 - $num;
                }
                phpc_native_ht_set_string_key_long($destPtr, $key, $num);
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
                phpc_native_ht_set_string_key_double($destPtr, $key, $dstr);
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
                phpc_native_ht_set_string_key_bool($destPtr, $key, $b);
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
                phpc_native_ht_set_string_key($destPtr, $key, $str);
            } else {
                return 0;
            }
            $n = $n + 1;
        }

        return 1;
    }
}
