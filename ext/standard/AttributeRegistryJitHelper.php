<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Compile-time attribute table lookup for JIT/AOT reflection (#10086, php-in-PHP).
 *
 * SSOT over per-module JSON tables embedded at link time; replaces LLVM branch chains
 * in {@see \PHPCompiler\JIT\Builtin\AttributeRegistryLowering}.
 * php-src: Zend/zend_attributes.c — compile-time attribute tables (semantics only)
 */
final class AttributeRegistryJitHelper
{
    /** @return array<string, list<string>> */
    private static function decodeClassNames(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, array<string, list<string>>> */
    private static function decodeMethodNames(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public static function classCount(string $classLc, string $classNamesJson): int
    {
        foreach (self::decodeClassNames($classNamesJson) as $key => $names) {
            if (0 === strcasecmp($classLc, $key)) {
                return \count($names);
            }
        }

        return 0;
    }

    public static function classNameAt(string $classLc, int $idx, string $classNamesJson): string
    {
        foreach (self::decodeClassNames($classNamesJson) as $key => $names) {
            if (0 === strcasecmp($classLc, $key)) {
                return $names[$idx] ?? '';
            }
        }

        return '';
    }

    public static function methodCount(string $classLc, string $methodLc, string $methodNamesJson): int
    {
        foreach (self::decodeMethodNames($methodNamesJson) as $classKey => $methods) {
            if (0 !== strcasecmp($classLc, $classKey)) {
                continue;
            }
            foreach ($methods as $methodKey => $names) {
                if (0 === strcasecmp($methodLc, $methodKey)) {
                    return \count($names);
                }
            }
        }

        return 0;
    }

    public static function methodNameAt(
        string $classLc,
        string $methodLc,
        int $idx,
        string $methodNamesJson
    ): string {
        foreach (self::decodeMethodNames($methodNamesJson) as $classKey => $methods) {
            if (0 !== strcasecmp($classLc, $classKey)) {
                continue;
            }
            foreach ($methods as $methodKey => $names) {
                if (0 === strcasecmp($methodLc, $methodKey)) {
                    return $names[$idx] ?? '';
                }
            }
        }

        return '';
    }
}
