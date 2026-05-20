<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** file_exists() — VM only (issue #194). */
final class file_exists extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('file_exists() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('file_exists() requires a string path in this compiler build');
        }
        $frame->returnVar->bool(@file_exists($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('file_exists() is not implemented for JIT in this compiler build');
    }
}
