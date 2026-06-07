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
            case 'array_push':
            case 'array_pop':
            case 'array_shift':
            case 'array_unshift':
            case 'asort':
            case 'arsort':
            case 'ksort':
            case 'krsort':
            case 'natcasesort':
            case 'natsort':
            case 'rsort':
            case 'shuffle':
            case 'sort':
            case 'uasort':
            case 'uksort':
            case 'usort':
                return [0];
            case 'modf':
                return [1];
            case 'frexp':
                return [1];
            case 'parse_str':
                return [1];
            case 'stream_socket_client':
                return [1, 2];
            case 'settype':
                return [0];
            case 'similar_text':
                return [2];
            case 'headers_sent':
                return [0, 1];
        }

        return [];
    }

    /** First argument index passed by reference for variadic tail (issue #3190). */
    public static function variadicByRefFromIndex(string $name): ?int
    {
        if ('sscanf' === strtolower($name)) {
            return 2;
        }

        return null;
    }
}
