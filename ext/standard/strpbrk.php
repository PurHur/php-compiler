<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrpbrk;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** strpbrk() for two strings — VM SSOT VmString; JIT/AOT via StringStrpbrk LLVM (#14791, #27055). */
final class strpbrk extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strpbrk() requires exactly two arguments in this compiler build');
        }
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29784 / #21444).
        $haystackStr = VmString::trimFamilyStringArgForFrame($frame, 0, 'strpbrk', 0, 'string');
        $maskStr = VmString::trimFamilyStringArgForFrame($frame, 1, 'strpbrk', 1, 'characters');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::strpbrk($haystackStr, $maskStr);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('strpbrk() requires exactly two arguments in this compiler build');
        }

        StringStrpbrk::ensureLinked($context);

        // Soft-null outside strict_types — Zend 8.4 deprecate+coerce (#21444); strict → TypeError (#29784).
        $hay = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strpbrk', 0, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strpbrk', 0, 'string');
        $mask = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'strpbrk', 1, 'characters')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'strpbrk', 1, 'characters');

        return JitStrpbrk::find($context, $hay, $mask);
    }
}
