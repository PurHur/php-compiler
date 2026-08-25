<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** NestedJIT: skip O: header → pos after `{` (#34594). Tiny TU. */
final class UnserializeDateTimeHeaderNestedJitHelper
{
    public static function afterBrace(string $payload): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($len < 5 || 'O' !== $payload[0]) {
            return -1;
        }
        $pos = 2;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            ++$pos;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos += 2;
        while ($pos < $len && '"' !== $payload[$pos]) {
            ++$pos;
        }
        if ($pos >= $len || '"' !== $payload[$pos]) {
            return -1;
        }
        ++$pos;
        if ($pos >= $len || ':' !== $payload[$pos]) {
            return -1;
        }
        ++$pos;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            ++$pos;
        }
        if ($pos >= $len || ':' !== $payload[$pos] || '{' !== $payload[$pos + 1]) {
            return -1;
        }

        return $pos + 2;
    }
}
