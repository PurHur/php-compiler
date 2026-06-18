<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Clone-with readonly reinit window for compiled JIT modules (#9498, php-in-PHP).
 *
 * VM SSOT delegates via {@see \PHPCompiler\VM\CloneWithSupport}; this helper mirrors that
 * logic for JIT/AOT embed using object addresses instead of {@see \PHPCompiler\VM\ObjectEntry}.
 * php-src: Zend/zend_objects.c — IS_PROP_REINITABLE during clone-with
 */
final class CloneWithJitHelper
{
    private static int $activeObjAddr = 0;

    /** @var list<string> */
    private static array $pendingProps = [];

    public static function begin(int $objAddr): void
    {
        self::$activeObjAddr = $objAddr;
        self::$pendingProps = [];
    }

    public static function addProperty(string $name): void
    {
        if ('' === $name) {
            return;
        }
        self::$pendingProps[] = $name;
    }

    public static function end(int $objAddr): void
    {
        if (self::$activeObjAddr === $objAddr) {
            self::$activeObjAddr = 0;
            self::$pendingProps = [];
        }
    }

    /** @return bool LLVM i1 ABI; bridge zext when needed */
    public static function tryConsume(int $objAddr, string $propName): bool
    {
        if (self::$activeObjAddr !== $objAddr) {
            return false;
        }
        $idx = array_search($propName, self::$pendingProps, true);
        if (false === $idx) {
            return false;
        }
        array_splice(self::$pendingProps, (int) $idx, 1);

        return true;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$activeObjAddr = 0;
        self::$pendingProps = [];
    }
}
