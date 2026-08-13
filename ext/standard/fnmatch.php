<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fnmatch() — POSIX glob pattern match (VM via VmFnmatchPure; JIT/AOT via StringFnmatch + FnmatchJitHelper, #3189/#7756/#12075/#30383). */
final class fnmatch extends Internal
{
    public function __construct()
    {
        parent::__construct('fnmatch');
    }

    public function execute(Frame $frame): void
    {
        // php-src fnmatch.c / basic_functions.stub.php — 2..3 (#30554).
        $this->requireArgCountRange($frame, 'fnmatch', 2, 3);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        // php-src fnmatch.c / basic_functions.stub.php — Z_PARAM_STR pattern+filename:
        // caller strict_types → TypeError on null (#30123); else DEP+coerce (#20554, #21366, #29660).
        // VmString argIndex is 0-based (helpers add +1 for the user-facing parameter number).
        $pattern = VmString::trimFamilyStringArgForFrame($frame, 0, 'fnmatch', 0, 'pattern');
        $filename = VmString::trimFamilyStringArgForFrame($frame, 1, 'fnmatch', 1, 'filename');
        $flags = 0;
        if (3 === $argc) {
            // VmMath userArgIndex is already 1-based (no +1 in intBuiltinTypeError).
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'fnmatch', 3, 'flags');
        }
        $frame->returnVar->bool(VmFnmatch::match($pattern, $filename, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireArgCountRangeJit($context, $args, 'fnmatch', 2, 3)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $argc = \count($args);
        $i32 = $context->getTypeFromString('int32');
        $flags = $i32->constInt(0, false);
        if (3 === $argc) {
            $flags = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'fnmatch() flags'),
                $i32
            );
        }

        // Soft-null outside strict_types — Zend 8.4 deprecate+coerce; strict → TypeError (#30123).
        // Early return after compile-time null TypeError — no helper IR after abort (peer strrchr #29889).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'fnmatch', 0, 'pattern');

                return $context->getTypeFromString('int1')->constInt(0, false);
            }
            $pattern = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'fnmatch', 0, 'pattern');
        } else {
            $pattern = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'fnmatch', 0, 'pattern')
                : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'fnmatch', 0, 'pattern');
        }

        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'fnmatch', 1, 'filename');

                return $context->getTypeFromString('int1')->constInt(0, false);
            }
            $filename = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'fnmatch', 1, 'filename');
        } else {
            $filename = $context->callerStrictTypes
                ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'fnmatch', 1, 'filename')
                : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'fnmatch', 1, 'filename');
        }

        return JitFnmatch::invoke($context, $pattern, $filename, $flags);
    }
}
