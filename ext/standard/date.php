<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** date() — format local time (subset; JIT/AOT via __compiler_format_datetime). */
final class date extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('date() expects at least 1 argument, 0 given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError('date() expects at most 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $format = self::vmFormatArg($frame);
        $timestamp = null;
        if (2 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArg($frame->calledArgs[1], 'date', 2, 'timestamp');
        }
        $frame->returnVar->string(VmDate::date($format, $timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDate::formatDate($context, false, ...$args);
    }

    private static function vmFormatArg(Frame $frame): string
    {
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'date', 'format', 0);

        return VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'date', 0, 'format');
    }
}
