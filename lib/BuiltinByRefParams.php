<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * By-reference parameter indices for VM builtins (issue #3578).
 *
 * @return list<int>
 */
final class BuiltinByRefParams
{
    public static function forFunction(string $name): array
    {
        switch (strtolower($name)) {
            case 'modf':
                return [1];
            case 'frexp':
                return [1];
        }

        return [];
    }
}
