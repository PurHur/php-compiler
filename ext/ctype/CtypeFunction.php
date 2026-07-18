<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for ctype builtins (php-src ext/ctype/ctype.c; #7253).
 */
abstract class CtypeFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException($this->getName().'() requires exactly one argument in this compiler build');
        }
        $spec = VmCtype::specForFunction($this->getName());
        $result = VmCtype::evaluate(
            $frame->calledArgs[0],
            $this->getName(),
            $spec['kind'],
            $spec['allow_digits'],
            $spec['allow_minus'],
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException($this->getName().'() requires exactly one argument in this compiler build');
        }

        return JitCtype::invoke($context, $args[0], $this->getName());
    }
}
