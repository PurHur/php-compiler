<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** gmdate() — format UTC time (subset; JIT/AOT via __compiler_format_datetime). */
final class gmdate extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('gmdate() expects at least 1 argument, 0 given');
        }
        if ($argc > 2) {
            throw new \ArgumentCountError('gmdate() expects at most 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $format = self::vmFormatArg($frame);
        $timestamp = null;
        if (2 === $argc) {
            $timestamp = VmDate::coerceNullableTimestampArg($frame->calledArgs[1], 'gmdate', 2, 'timestamp');
        }
        $frame->returnVar->string(VmDate::gmdate($format, $timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDate::formatDate($context, true, ...$args);
    }

    private static function vmFormatArg(Frame $frame): string
    {
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'gmdate', 'format', 0, $frame);

        return VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gmdate', 0, 'format');
    }
}
