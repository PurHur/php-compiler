<?php

declare(strict_types=1);

/**
 * vfprintf() — formatted write to stream with args array (ext/standard/formatted_print.c parity, #3752).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class vfprintf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('vfprintf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \LogicException('vfprintf() requires exactly three arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $argsVar = $frame->calledArgs[2]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'vfprintf', 1);
        $format = VmString::requireStringBuiltinArg($frame->calledArgs[1], 'vfprintf', 1, 'format');
        $written = VmVprintf::vfprintf(
            $handle,
            $format,
            $argsVar,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($written);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('vfprintf() requires exactly three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'vfprintf() stream'),
            $i64
        );
        $fmt = JitStringBuiltinArg::lowerRequiredString($context, $args[1], 'vfprintf', 1, 'format');
        $argsArray = JitVfprintf::loadArgsArray($context, $args[2], 'vfprintf');

        return JitVfprintf::invoke($context, $handle, $fmt, $argsArray);
    }
}
