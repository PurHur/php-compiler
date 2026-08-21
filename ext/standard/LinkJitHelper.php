<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * link() for compiled JIT/AOT modules (php-in-PHP, #15544 / #33406).
 *
 * Leaf is `@link` → NestedJIT whitelist {@see link_} →
 * {@see \PHPCompiler\JIT\Builtin\StringLink::invokeNestedLeaf} (no kernel).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / rename #29141).
 * php-src: ext/standard/link.c — php_link
 */
final class LinkJitHelper
{
    /** @return int 1 on success, 0 on failure */
    public static function invokeArgv(string $target, string $link): int
    {
        if (self::pathHasNulByte($target) || self::pathHasNulByte($link)) {
            TriggerErrorJitHelper::warning('link(): No such file or directory');

            return 0;
        }
        $ok = @\link($target, $link);
        if (!$ok) {
            TriggerErrorJitHelper::warning('link(): No such file or directory');
        }

        return $ok ? 1 : 0;
    }

    /** NestedJIT-safe embedded-NUL check (#29141). */
    private static function pathHasNulByte(string $path): bool
    {
        $n = \strlen($path);
        for ($i = 0; $i < $n; ++$i) {
            if ("\0" === $path[$i]) {
                return true;
            }
        }

        return false;
    }
}
