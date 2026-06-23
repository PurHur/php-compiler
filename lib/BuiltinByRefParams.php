<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

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
            case 'array_multisort':
            case 'array_push':
            case 'array_pop':
            case 'array_shift':
            case 'array_unshift':
            case 'current':
            case 'end':
            case 'key':
            case 'next':
            case 'prev':
            case 'reset':
            case 'array_walk':
            case 'array_walk_recursive':
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
            case 'fsockopen':
            case 'pfsockopen':
                return [2, 3];
            case 'settype':
                return [0];
            case 'similar_text':
                return [2];
            case 'preg_match':
            case 'preg_match_all':
                return [2];
            case 'str_replace':
            case 'str_ireplace':
                return [3];
            case 'headers_sent':
                return [0, 1];
            case 'flock':
                return [2];
            case 'getopt':
                return [2];
            case 'is_callable':
                return [2];
            case 'openssl_random_pseudo_bytes':
                return [1];
        }

        return [];
    }

    /** First argument index passed by reference for variadic tail (issue #3190). */
    public static function variadicByRefFromIndex(string $name): ?int
    {
        if (\in_array(strtolower($name), ['sscanf', 'vfscanf', 'fscanf'], true)) {
            return 2;
        }
        if ('array_multisort' === strtolower($name)) {
            return 0;
        }

        return null;
    }

    /**
     * Whether $argIndex is ZEND_SEND_REF for $name.
     * array_multisort() only passes array operands by reference, not SORT_* flags (#9481, ext/standard/array.c).
     */
    public static function isByRefArg(string $name, int $argIndex, ?Variable $runtimeValue = null): bool
    {
        $lc = strtolower($name);
        if (\in_array($argIndex, self::forFunction($lc), true)) {
            return true;
        }
        $variadicFrom = self::variadicByRefFromIndex($lc);
        if (null === $variadicFrom || $argIndex < $variadicFrom) {
            return false;
        }
        if ('array_multisort' === $lc) {
            if (null === $runtimeValue) {
                return false;
            }

            return Variable::TYPE_ARRAY === $runtimeValue->resolveIndirect()->type;
        }

        return true;
    }
}
