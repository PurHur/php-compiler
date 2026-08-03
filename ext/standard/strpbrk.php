<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrpbrk;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strpbrk() for two strings — VM SSOT VmString; JIT/AOT via StringStrpbrk LLVM (#14791, #27055). */
final class strpbrk extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strpbrk() requires exactly two arguments in this compiler build');
        }
        $haystackStr = VmString::coerceTrimFamilyStringArg($frame->calledArgs[0], 'strpbrk', 0, 'string');
        $maskStr = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strpbrk', 1, 'characters');
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

        return JitStrpbrk::find(
            $context,
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strpbrk', 0, 'string'),
            JitStringBuiltinArg::lower($context, $args[1], 'strpbrk', 1, 'characters')
        );
    }
}
