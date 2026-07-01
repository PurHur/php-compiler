<?php

declare(strict_types=1);

/**
 * vprintf() — formatted write to stdout with args array (ext/standard/formatted_print.c parity, #3752).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
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
        $fmtVar = $frame->calledArgs[0]->resolveIndirect();
        $argsVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $fmtVar->type) {
            throw new \LogicException('vprintf() format must be a string in this compiler build');
        }
        $written = VmVprintf::vprintf($fmtVar->toString(), $argsVar, $frame);
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
        $fmt = JitStringArg::lower($context, $args[0], 'vprintf() format');
        $argsArray = JitVfprintf::loadArgsArray($context, $args[1]);

        return JitVfprintf::invoke($context, $stdout, $fmt, $argsArray);
    }
}
