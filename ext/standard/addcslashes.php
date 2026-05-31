<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** addcslashes() — C-style selective escaping (php-src ext/standard/string.c; issue #3356). */
final class addcslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('addcslashes() requires exactly two arguments in this compiler build');
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        $charlist = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $subject->type || Variable::TYPE_STRING !== $charlist->type) {
            throw new \LogicException('addcslashes() requires string arguments in this compiler build');
        }
        $frame->returnVar->string(VmString::addcslashes($subject->toString(), $charlist->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('addcslashes() requires exactly two arguments in this compiler build');
        }
        StringCslashes::ensureLinked($context);

        return JitAddcslashes::escape(
            $context,
            $this->jitString($context, $args[0], 'addcslashes() argument #1'),
            $this->jitString($context, $args[1], 'addcslashes() argument #2')
        );
    }
}
