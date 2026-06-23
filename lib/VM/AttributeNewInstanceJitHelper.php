<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for ReflectionAttribute::newInstance() (#10274, php-in-PHP).
 *
 * SSOT: {@see ReflectionSupport}, {@see \PHPCompiler\VM\Builtin\ReflectionAttributeNewInstance}
 * php-src: ext/reflection/php_reflection.c — ReflectionAttribute::newInstance()
 */
final class AttributeNewInstanceJitHelper
{
    /**
     * Case-insensitive declared-class lookup for attribute instantiation.
     *
     * @param string $packedLowerNames NUL-separated declared class lower names (JIT embed)
     * @param string $packedIdsCsv      comma-separated parallel class ids
     */
    public static function resolveClassId(string $name, string $packedLowerNames, string $packedIdsCsv): int
    {
        if ('' === $packedLowerNames) {
            return -1;
        }
        $names = explode("\0", $packedLowerNames);
        $ids = explode(',', $packedIdsCsv);
        $needle = strtolower(ltrim($name, '\\'));
        foreach ($names as $i => $candidate) {
            if ('' === $candidate) {
                continue;
            }
            if (0 === strcasecmp($needle, $candidate)) {
                return (int) ($ids[$i] ?? -1);
            }
        }

        return -1;
    }
}
