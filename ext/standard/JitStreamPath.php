<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\PathSupport;
use PHPLLVM\Value;

/** LLVM empty-path guards for stream/file builtins (php-src streamsfuncs.c; #11016). */
final class JitStreamPath
{
    public static function lowerNonEmptyPath(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'filename'
    ): Value {
        // Z_PARAM_PATH: null→"" even on 8.4 forward profile; empty path → ValueError (#19145, ext/standard/image.c).
        $path = JitStringBuiltinArg::lowerPath(
            $context,
            $arg,
            $function,
            $argIndex,
            $paramName,
            'string',
            null,
            true
        );
        JitStringBuiltinArg::rejectEmpty($context, $arg, $path, PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);

        return $path;
    }
}
