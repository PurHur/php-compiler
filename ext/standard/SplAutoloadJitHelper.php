<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * spl_autoload_register() callback stack for compiled JIT/AOT (#9238, php-in-PHP).
 *
 * Stack prepend/append semantics match {@see VmSplAutoload}. JIT/AOT link via
 * {@see \PHPCompiler\JIT\Builtin\SplAutoloadOutput} thin bridges.
 * php-src: Zend/zend_autoload.c, ext/spl/spl_functions.c
 */
final class SplAutoloadJitHelper
{
    public const MAX = 32;

    /** @var list<int> opaque autoload shim addresses (uintptr) */
    private static array $fnStack = [];

    /** @var list<int> opaque __value__* metadata for spl_autoload_functions() */
    private static array $metaStack = [];

    public static function registerApply(int $fnOpaque, int $metaOpaque, bool $prepend): void
    {
        if (0 === $fnOpaque || \count(self::$fnStack) >= self::MAX) {
            return;
        }
        if ($prepend && \count(self::$fnStack) > 0) {
            \array_unshift(self::$fnStack, $fnOpaque);
            \array_unshift(self::$metaStack, $metaOpaque);

            return;
        }
        self::$fnStack[] = $fnOpaque;
        self::$metaStack[] = $metaOpaque;
    }

    public static function unregisterApply(int $fnOpaque): bool
    {
        if (0 === $fnOpaque) {
            return false;
        }
        foreach (self::$fnStack as $index => $stored) {
            if ($stored === $fnOpaque) {
                \array_splice(self::$fnStack, $index, 1);
                \array_splice(self::$metaStack, $index, 1);

                return true;
            }
        }

        return false;
    }

    public static function depth(): int
    {
        return \count(self::$fnStack);
    }

    public static function fnOpaqueAt(int $index): int
    {
        return self::$fnStack[$index] ?? 0;
    }

    public static function metaOpaqueAt(int $index): int
    {
        return self::$metaStack[$index] ?? 0;
    }

    /** @internal test reset */
    public static function resetForTests(): void
    {
        self::$fnStack = [];
        self::$metaStack = [];
    }
}
