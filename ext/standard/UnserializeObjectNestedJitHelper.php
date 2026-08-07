<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin-standalone NestedJIT unserialize() for simple O: public-prop objects (#27030).
 *
 * Parses `O:len:"Class":n:{s:…;i:…;}` — packed int props via phpc_native_ht_set_long_at
 * (string keys miscompile under NestedJIT; peer JsonDecode uses long_at for arrays).
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
            ++$pos;
        }
        if ($pos >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return '';
        }
        $pos += 2;
        $name = '';
        $i = 0;
        while ($i < $nameLen && $pos < $len) {
            $name .= $payload[$pos];
            ++$pos;
            ++$i;
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
            ++$pos;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        while ($pos < $len && '"' !== $payload[$pos]) {
            ++$pos;
        }
        if ($pos >= $len || '"' !== $payload[$pos]) {
            return 0;
        }
        ++$pos;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return 0;
        }
        ++$pos;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            ++$pos;
        }
        if ($pos >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        if ($pos + 1 >= $len || 's' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            ++$pos;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        while ($pos < $len && '"' !== $payload[$pos]) {
            ++$pos;
        }
        if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        if ($pos >= $len || 'i' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        $neg = false;
        if ($pos < $len && '-' === $payload[$pos]) {
            $neg = true;
            ++$pos;
        }
        $num = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $num = $num * 10 + (\ord($payload[$pos]) - 48);
            ++$pos;
        }
        if ($neg) {
            $num = 0 - $num;
        }

        return $num;
    }

    /** @return int 1 on success */
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
            ++$pos;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        while ($pos < $len && '"' !== $payload[$pos]) {
            ++$pos;
        }
        if ($pos >= $len || '"' !== $payload[$pos]) {
            return 0;
        }
        ++$pos;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return 0;
        }
        ++$pos;
        $propCount = 0;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            $propCount = $propCount * 10 + (\ord($payload[$pos]) - 48);
            ++$pos;
        }
        if ($pos >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return 0;
        }
        $pos += 2;
        $n = 0;
        while ($n < $propCount && $pos < $len) {
            if ('s' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos += 2;
            $klen = 0;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                $klen = $klen * 10 + (\ord($payload[$pos]) - 48);
                ++$pos;
            }
            if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos += 2;
            $key = '';
            $ki = 0;
            while ($ki < $klen && $pos < $len) {
                $key .= $payload[$pos];
                ++$pos;
                ++$ki;
            }
            if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos += 2;
            if ($pos >= $len || 'i' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
                return 0;
            }
            $pos += 2;
            $neg = false;
            if ($pos < $len && '-' === $payload[$pos]) {
                $neg = true;
                ++$pos;
            }
            $num = 0;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                $num = $num * 10 + (\ord($payload[$pos]) - 48);
                ++$pos;
            }
            if ($neg) {
                $num = 0 - $num;
            }
            if ($pos >= $len || ';' !== $payload[$pos]) {
                return 0;
            }
            ++$pos;
            phpc_native_ht_set_long_at($destPtr, $n, $num);
            ++$n;
        }

        return 1;
    }
}
