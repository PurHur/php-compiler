<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\JIT\Context;

/** Record compile-time attribute tables for JIT/AOT reflection (#1936, #5621). */
final class AttributeRegistry
{
    public static function registerDeclarations(Context $context): void
    {
        ReflectionNative::registerDeclarations($context);
    }

    /**
     * @param list<string>|list<AttributeEntry> $namesOrEntries
     */
    public static function emitRegisterClass(Context $context, string $classLc, array $namesOrEntries): void
    {
        self::registerDeclarations($context);
        AttributeRegistryLowering::recordClass($classLc, $namesOrEntries);
    }

    /** @param list<string> $names */
    public static function emitRegisterMethod(Context $context, string $classLc, string $methodLc, array $names): void
    {
        self::registerDeclarations($context);
        AttributeRegistryLowering::recordMethod($classLc, $methodLc, $names);
    }
}
