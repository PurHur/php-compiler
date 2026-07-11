<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Compile-time #[\SensitiveParameter] index tables for JIT/AOT reflection (#16130).
 *
 * php-src: ext/reflection/php_reflection.c — ReflectionParameter::isSensitiveParameter()
 */
final class ParamSensitiveJitHelper
{
    /** @return array<string, list<int>> */
    private static function decodeFunctionParams(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, array<string, list<int>>> */
    private static function decodeMethodParams(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public static function functionParamIsSensitive(string $funcLc, int $paramIndex, string $json): bool
    {
        foreach (self::decodeFunctionParams($json) as $key => $indices) {
            if (0 !== strcasecmp($funcLc, $key)) {
                continue;
            }
            foreach ($indices as $idx) {
                if ((int) $idx === $paramIndex) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function methodParamIsSensitive(
        string $classLc,
        string $methodLc,
        int $position,
        string $json
    ): bool {
        foreach (self::decodeMethodParams($json) as $classKey => $methods) {
            if (0 !== strcasecmp($classLc, $classKey)) {
                continue;
            }
            foreach ($methods as $methodKey => $positions) {
                if (0 !== strcasecmp($methodLc, $methodKey)) {
                    continue;
                }
                foreach ($positions as $pos) {
                    if ((int) $pos === $position) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
