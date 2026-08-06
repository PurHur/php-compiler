<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringUrldecode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** urldecode() for strings (subset of PHP; JIT/AOT via __string__urldecode). */
final class urldecode extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/url.stub.php — ArgumentCountError (#28316).
        $this->requireExactArgCount($frame, 'urldecode', 1);
        // Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/url.c)
        $subject = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'urldecode',
            0,
            'string'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::urldecode($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer #28317 / #28316.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('urldecode() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }

        // Null → soft-coerce to "" without helper IR (urldecode("") === ""; #21188).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'urldecode', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'urldecode', 0, 'string');
        }

        $literal = $args[0]->compileTimeString ?? null;
        if (null !== $literal) {
            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(VmString::urldecode($literal))
                )
            );
        }

        StringUrldecode::ensureLinked($context);
        $str = self::jitStringArg($context, $args[0]);

        return JitUrlencode::urldecode($context, $str);
    }

    /** Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/url.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'urldecode',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'urldecode',
            0,
            'string'
        );
    }
}
