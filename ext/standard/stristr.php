<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stristr() for two strings (subset of PHP; LLVM via VmStringCompare CI scan + slice). */
final class stristr extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (peer #28228).
        $this->requireArgCountRange($frame, 'stristr', 2, 3);
        $argc = \count($frame->calledArgs);
        $haystackStr = VmString::coerceTrimFamilyStringArg($frame->calledArgs[0], 'stristr', 0, 'haystack');
        $needleStr = VmString::coerceTrimFamilyStringArg($frame->calledArgs[1], 'stristr', 1, 'needle');
        $beforeNeedle = false;
        if (3 === $argc) {
            // Z_PARAM_BOOL — null→false + E_DEPRECATED (php-src string.c; #21702).
            $beforeNeedle = VmMath::parseBoolBuiltinArgForFrame($frame, 2, 'stristr', 3, 'before_needle');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::stristr($haystackStr, $needleStr, $beforeNeedle);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28311 / #28228.
        if (!$this->requireArgCountRangeJit($context, $args, 'stristr', 2, 3)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $before = null;
        if (3 === $argc) {
            $i8 = $context->getTypeFromString('int8');
            $before = $context->builder->zExt(
                JitBoolArg::lowerCoerceZParamBool($context, $args[2], 'stristr', 'before_needle', 3),
                $i8
            );
        }

        return JitStrstr::find(
            $context,
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'stristr', 0, 'haystack'),
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'stristr', 1, 'needle'),
            $before,
            true
        );
    }
}
