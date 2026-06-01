<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strpbrk() for two strings (subset of PHP; LLVM via libc strpbrk + slice). */
final class strpbrk extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strpbrk() requires exactly two arguments in this compiler build');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $mask = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::strpbrk(
            VmString::coerceOperand($haystack),
            VmString::coerceOperand($mask)
        );
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

        return JitStrpbrk::find(
            $context,
            $this->jitString($context, $args[0], 'strpbrk() argument #1'),
            $this->jitString($context, $args[1], 'strpbrk() argument #2')
        );
    }
}
