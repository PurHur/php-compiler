<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** addslashes() — escape quotes, backslash, and NUL (subset of PHP; native LLVM in JIT/AOT). */
final class addslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('addslashes() requires exactly one argument in this compiler build');
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $subject->type) {
            throw new \LogicException('addslashes() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::addslashes($subject->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('addslashes() requires exactly one argument in this compiler build');
        }

        return JitAddslashes::escape($context, $this->jitString($context, $args[0], 'addslashes() argument #1'));
    }
}
