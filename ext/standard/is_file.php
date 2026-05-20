<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** is_file() — VM only (issue #194). */
final class is_file extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_file() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('is_file() requires a string path in this compiler build');
        }
        $frame->returnVar->bool(@is_file($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('is_file() is not implemented for JIT in this compiler build');
    }
}
