<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_shuffle() — Fisher–Yates byte shuffle (subset of PHP; CSPRNG).
 */
final class str_shuffle extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28313).
        $this->requireExactArgCount($frame, 'str_shuffle', 1);
        $subject = self::vmStringArg($frame);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::strShuffle($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286 / #28313.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('str_shuffle() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }

        return JitStrShuffle::shuffle(
            $context,
            self::jitStringArg($context, $args[0])
        );
    }

    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'str_shuffle', 'string')->toString();
        }

        // Soft-null — coerce+deprecate on forward profile (#24598, reverts #24213; string.c).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'str_shuffle',
            0,
            'string'
        );
    }

    /** Soft-null DEP+coerce on forward profile (#24598, reverts #24213; ext/standard/string.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'str_shuffle', 0, 'string');
        }

        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'str_shuffle', 0, 'string');
    }
}
