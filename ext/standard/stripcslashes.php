<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stripcslashes() — unescape C-style byte sequences (php-src ext/standard/string.c; issue #3356).
 *
 * Soft-null on forward profile like addslashes/stripslashes (#21220 / #21180).
 * Excess argc → ArgumentCountError like Zend (#30704).
 */
final class stripcslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#30704).
        $this->requireExactArgCount($frame, 'stripcslashes', 1);
        $subject = self::vmStringArg($frame, 0, 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::stripcslashes($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'stripcslashes', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'stripcslashes', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'stripcslashes', 0, 'string');
        }

        $subjectLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null !== $subjectLit) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::stripcslashes($subjectLit))
            );
        }

        StringCslashes::ensureStripcslashes($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_stripcslashes'),
            self::jitStringArg($context, $args[0], 0, 'string')
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'stripcslashes', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'stripcslashes',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'stripcslashes',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'stripcslashes',
            $argIndex,
            $paramName
        );
    }
}
