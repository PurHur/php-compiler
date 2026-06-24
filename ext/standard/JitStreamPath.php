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
        $path = JitStringBuiltinArg::lower($context, $arg, $function, $argIndex, $paramName);
        JitStringBuiltinArg::rejectEmpty($context, $arg, $path, PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);

        return $path;
    }
}
