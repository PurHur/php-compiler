<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

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
}
