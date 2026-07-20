<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * convert_cyr_string() — Cyrillic charset conversion (php-src cyr_convert.c, #4649).
 *
 * Removed in php-src 8.0; registered only when {@see \PHPCompiler\CompilerVersion::supportsConvertCyrString()}
 * (pre-8.0 legacy profiles). #21481.
 */
final class convert_cyr_string extends Internal
{
    public function __construct()
    {
        parent::__construct('convert_cyr_string');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('convert_cyr_string() expects exactly 3 arguments, %d given', $argc)
            );
        }

        $str = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'convert_cyr_string', 0, 'str');
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'convert_cyr_string', 1, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'convert_cyr_string', 2, 'to');
        if (null === $frame->returnVar) {
            return;
        }

        $frame->returnVar->string(VmConvertCyrString::convert($str, $from, $to, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitConvertCyrString::invoke($context, ...$args);
    }
}
