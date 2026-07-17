<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_shuffle() — Fisher–Yates byte shuffle (subset of PHP; CSPRNG).
 */
final class str_shuffle extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_shuffle() requires exactly one argument');
        }
        $subject = VmString::trimFamilyStringArgForFrame($frame, 0, 'str_shuffle', 0, 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::strShuffle($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('str_shuffle() requires exactly one argument');
        }

        return JitStrShuffle::shuffle(
            $context,
            self::jitStringArg($context, $args[0])
        );
    }

    /** Soft-null — coerce+deprecate on forward profile (#19998, ext/standard/string.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'str_shuffle', 0, 'string');
        }

        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'str_shuffle', 0, 'string');
    }
}
