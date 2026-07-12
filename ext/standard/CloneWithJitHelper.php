<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\CloneWithSupport;
use PHPCompiler\VM\ObjectRegistry;

/**
 * Clone-with readonly reinit window for compiled JIT modules (#9498, php-in-PHP).
 *
 * VM SSOT delegates via {@see \PHPCompiler\VM\CloneWithSupport}; this helper mirrors that
 * logic for JIT/AOT embed using object addresses instead of {@see \PHPCompiler\VM\ObjectEntry}.
 * php-src: Zend/zend_objects.c — IS_PROP_REINITABLE during clone-with / __clone (#15365)
 */
final class CloneWithJitHelper
{
    private static int $activeObjAddr = 0;

    /** @var list<string> */
    private static array $pendingProps = [];

    /** VM {@see ObjectEntry::id} with an open __clone / clone-with reinit window (#15365). */
    private static int $activeVmCloneObjectId = 0;

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

    public static function registerVmCloneReinit(int $objectId): void
    {
        if ($objectId > 0) {
            self::$activeVmCloneObjectId = $objectId;
        }
    }

    public static function unregisterVmCloneReinit(int $objectId): void
    {
        if (self::$activeVmCloneObjectId === $objectId) {
            self::$activeVmCloneObjectId = 0;
        }
    }

    /** @return bool LLVM i1 ABI; bridge zext when needed */
    public static function tryConsume(int $objAddr, string $propName): bool
    {
        if (self::$activeObjAddr === $objAddr) {
            $idx = array_search($propName, self::$pendingProps, true);
            if (false !== $idx) {
                $next = [];
                foreach (self::$pendingProps as $i => $p) {
                    if ($i !== $idx) {
                        $next[] = $p;
                    }
                }
                self::$pendingProps = $next;

                return true;
            }
        }
        if (self::$activeVmCloneObjectId > 0) {
            $entry = ObjectRegistry::find(self::$activeVmCloneObjectId);
            if (null !== $entry && CloneWithSupport::consumeReinit($entry, $propName)) {
                return true;
            }
        }

        return false;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$activeObjAddr = 0;
        self::$pendingProps = [];
        self::$activeVmCloneObjectId = 0;
    }
}
