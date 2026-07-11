<?php

declare(strict_types=1);

/**
 * vprintf() — formatted write to stdout with args array (ext/standard/formatted_print.c parity, #3752).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class vprintf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('vprintf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'vprintf() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $format = VmString::requireStringBuiltinArg($frame->calledArgs[0], 'vprintf', 0, 'format');
        $argsVar = $frame->calledArgs[1]->resolveIndirect();
        $written = VmVprintf::vprintf($format, $argsVar, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($written);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'vprintf() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $i64 = $context->getTypeFromString('int64');
        $stdout = $i64->constInt(1, false);
        $fmt = JitStringBuiltinArg::lowerRequiredString($context, $args[0], 'vprintf', 0, 'format');
        $argsArray = JitVfprintf::loadArgsArray($context, $args[1]);

        return JitVfprintf::invoke($context, $stdout, $fmt, $argsArray);
    }
}
