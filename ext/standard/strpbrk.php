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
        $charList = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $haystack->type || Variable::TYPE_STRING !== $charList->type) {
            throw new \LogicException('strpbrk() only supports strings in this compiler build');
        }
        $result = VmString::strpbrk($haystack->toString(), $charList->toString());
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
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('strpbrk() only supports strings in this compiler build');
        }

        return JitStrpbrk::find(
            $context,
            $context->helper->loadValue($args[0]),
            $context->helper->loadValue($args[1])
        );
    }
}
