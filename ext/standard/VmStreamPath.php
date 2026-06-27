<?php

declare(strict_types=1);

/** Shared empty-path guards for stream/file builtins (php-src streamsfuncs.c; #11016). */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\PathSupport;
use PHPCompiler\VM\Variable;

final class VmStreamPath
{
    /**
     * Coerce a path operand and reject empty string after null→"" coercion (php-src Z_PARAM_PATH).
     *
     * @throws \ValueError when the coerced path is empty
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceNonEmptyPathArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'filename'
    ): string {
        $path = VmString::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
        VmString::rejectNullByteBuiltinStringArg($path, $function, $argIndex, $paramName);
        if ('' === $path) {
            throw new \ValueError(PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
        }

        return $path;
    }
}
