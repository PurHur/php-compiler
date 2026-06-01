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

/** stripcslashes() — unescape C-style byte sequences (php-src ext/standard/string.c; issue #3356). */
final class stripcslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stripcslashes() requires exactly one argument in this compiler build');
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $subject->type) {
            throw new \LogicException('stripcslashes() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::stripcslashes($subject->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stripcslashes() requires exactly one argument in this compiler build');
        }
        StringCslashes::ensureLinked($context);

        return JitStripcslashes::unescape(
            $context,
            $this->jitString($context, $args[0], 'stripcslashes() argument #1')
        );
    }
}
