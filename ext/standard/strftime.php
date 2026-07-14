<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strftime() — locale time formatting via libc strftime (ext/standard/datetime.c, #3692). */
final class strftime extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('strftime() expects at least 1 argument, 0 given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError('strftime() expects at most 2 arguments, '.$argc.' given');
        }
        VmEngineBuiltinDeprecation::emitFunction($frame, 'strftime');
        if (null === $frame->returnVar) {
            return;
        }
        $format = self::vmFormatArg($frame);
        if (false === $format) {
            $frame->returnVar->bool(false);

            return;
        }
        $timestamp = null;
        if (2 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArgForFrame($frame, 1, 'strftime', 2, 'timestamp');
        }
        $frame->returnVar->string(VmDate::strftime($format, $timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDate::formatStrftime($context, false, ...$args);
    }

    /** @return string|false */
    private static function vmFormatArg(Frame $frame): string|false
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireString($frame, 0, 'strftime', 'format')->toString();
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return false;
        }

        return VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strftime', 0, 'format');
    }
}
