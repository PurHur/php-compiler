<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Builtin\LazyGhostCreateLazyGhost;
use PHPCompiler\VM\Builtin\LazyGhostMarkLazyObjectAsInitialized;

/**
 * Built-in LazyGhostTrait marker (PHP 8.4, Zend/zend_lazy_objects.c, #6096).
 *
 * Empty internal trait: no methods. Classes `use LazyGhostTrait` for serializer/DI
 * patterns alongside ReflectionClass::newLazyGhost().
 */
final class LazyGhostTraitSupport
{
    public const TRAIT_NAME = 'LazyGhostTrait';
    public const TRAIT_LC = 'lazyghosttrait';

    public static function register(Context $ctx): void
    {
        $entry = new ClassEntry(self::TRAIT_NAME);
        $entry->isTrait = true;
        $ctx->classes[self::TRAIT_LC] = $entry;
    }

    public static function isLazyGhostTrait(string $traitName): bool
    {
        return self::TRAIT_LC === strtolower(ltrim($traitName, '\\'));
    }

    /** True when this class or an ancestor `use LazyGhostTrait` (#6531). */
    public static function classUsesLazyGhostTrait(ClassEntry $class, ?Context $ctx = null): bool
    {
        $lcClass = strtolower(ltrim($class->name, '\\'));
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            $entry = null !== $ctx && isset($ctx->classes[$lcClass])
                ? $ctx->classes[$lcClass]
                : ($lcClass === strtolower(ltrim($class->name, '\\')) ? $class : null);
            if (null === $entry) {
                break;
            }
            if ($entry->usesLazyGhostTrait) {
                return true;
            }
            if (null === $entry->parentLc) {
                break;
            }
            $lcClass = $entry->parentLc;
        }

        return false;
    }

    /**
     * Inject LazyGhostTrait static/instance helpers (Zend/zend_lazy_objects.c, #6531).
     */
    public static function ensureBuiltinLazyGhostMethods(ClassEntry $entry): void
    {
        if (!$entry->usesLazyGhostTrait || $entry->isTrait || $entry->isInterface || $entry->isEnum) {
            return;
        }
        if (!isset($entry->methods['createlazyghost'])) {
            $entry->methods['createlazyghost'] = new LazyGhostCreateLazyGhost($entry);
            $entry->methodVisibility['createlazyghost'] = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
            $entry->methodNames['createlazyghost'] ??= 'createLazyGhost';
        }
        if (!isset($entry->methods['marklazyobjectasinitialized'])) {
            $entry->methods['marklazyobjectasinitialized'] = new LazyGhostMarkLazyObjectAsInitialized($entry);
            $entry->methodVisibility['marklazyobjectasinitialized'] = CfgFunc::FLAG_PUBLIC;
            $entry->methodNames['marklazyobjectasinitialized'] ??= 'markLazyObjectAsInitialized';
        }
    }
}
