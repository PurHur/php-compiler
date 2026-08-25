<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** NestedJIT: from bag pos skip s:N:"key"; → first char of s:M:"value" (#34594). Tiny TU. */
final class UnserializeDateTimeSkipKeyNestedJitHelper
{
    public static function valueStart(string $payload, int $pos): int
    {
        $payload = $payload.'';
        $len = \strlen($payload);
        if ($pos < 0 || $pos + 1 >= $len || 's' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos += 2;
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
        if ($pos + 1 >= $len || '"' !== $payload[$pos] || ';' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos += 2;
        if ($pos + 1 >= $len || 's' !== $payload[$pos] || ':' !== $payload[$pos + 1]) {
            return -1;
        }
        $pos += 2;
        while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
            ++$pos;
        }
        if ($pos + 1 >= $len || ':' !== $payload[$pos] || '"' !== $payload[$pos + 1]) {
            return -1;
        }

        return $pos + 2;
    }
}
