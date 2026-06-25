<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Compiler\AttributeEntry;
use PHPCompiler\JIT\Context;

/**
 * Compile-time attribute tables for JIT/AOT reflection (#1936, #5621, #10086).
 *
 * Lookup SSOT: {@see AttributeRegistryLookupRuntime} + {@see \PHPCompiler\ext\standard\AttributeRegistryJitHelper}
 */
final class AttributeRegistryLowering
{
    /** @var array<string, list<string>> */
    private static array $classNames = [];

    /** @var array<string, list<AttributeEntry>> */
    private static array $classEntries = [];

    /** @var array<string, array<string, list<string>>> */
    private static array $methodNames = [];

    /**
     * @param list<string>|list<AttributeEntry> $namesOrEntries
     */
    public static function recordClass(string $classLc, array $namesOrEntries): void
    {
        $names = [];
        $entries = [];
        foreach ($namesOrEntries as $item) {
            if ($item instanceof AttributeEntry) {
                $entries[] = $item;
                $names[] = ltrim($item->name, '\\');
            } else {
                $names[] = ltrim((string) $item, '\\');
            }
        }
        if ([] === $names) {
            return;
        }
        self::$classNames[$classLc] = $names;
        if ([] !== $entries) {
            self::$classEntries[$classLc] = $entries;
        }
    }

    /** @param list<string> $names */
    public static function recordMethod(string $classLc, string $methodLc, array $names): void
    {
        if ([] === $names) {
            return;
        }
        self::$methodNames[$classLc][$methodLc] = $names;
    }

    public static function ensureLinked(Context $context): void
    {
        ReflectionNative::registerDeclarations($context);
    }

    public static function implementLookupFunctions(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_attr_class_count');
        if (null !== $fn && $fn->countBasicBlocks() > 0) {
            return;
        }

        $classNames = self::$classNames;
        $classEntries = self::$classEntries;
        $methodNames = self::$methodNames;
        self::resetAccumulated();

        AttributeRegistryLookupRuntime::implement(
            $context,
            self::encodeClassNamesJson($classNames),
            self::encodeMethodNamesJson($methodNames),
            self::encodeClassEntriesJson($classEntries)
        );
        $context->builder->clearInsertionPosition();
    }

    /** @param array<string, list<string>> $classNames */
    private static function encodeClassNamesJson(array $classNames): string
    {
        if ([] === $classNames) {
            return '{}';
        }

        return (string) json_encode($classNames, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, array<string, list<string>>> $methodNames */
    private static function encodeMethodNamesJson(array $methodNames): string
    {
        if ([] === $methodNames) {
            return '{}';
        }

        return (string) json_encode($methodNames, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, list<AttributeEntry>> $classEntries */
    private static function encodeClassEntriesJson(array $classEntries): string
    {
        if ([] === $classEntries) {
            return '{}';
        }
        $payload = [];
        foreach ($classEntries as $classLc => $entries) {
            $payload[$classLc] = [];
            foreach ($entries as $entry) {
                $payload[$classLc][] = [
                    'name' => $entry->name,
                    'args' => $entry->args,
                ];
            }
        }

        return (string) json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public static function resetAccumulated(): void
    {
        self::$classNames = [];
        self::$classEntries = [];
        self::$methodNames = [];
    }
}
