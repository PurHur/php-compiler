<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fopen() — VM only; returns integer handle id (issue #194). */
final class fopen extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('fopen() requires exactly two arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        $modeVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type || Variable::TYPE_STRING !== $modeVar->type) {
            throw new \LogicException('fopen() path and mode must be strings in this compiler build');
        }
        $handle = VmFs::fopen($pathVar->toString(), $modeVar->toString());
        if (false === $handle) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($handle);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('fopen() is not implemented for JIT in this compiler build');
    }
}
