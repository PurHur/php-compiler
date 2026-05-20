<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fread() — VM only (issue #194). */
final class fread extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('fread() requires exactly two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $lenVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fread() handle must be an integer in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $lenVar->type) {
            throw new \LogicException('fread() length must be an integer in this compiler build');
        }
        $data = VmFs::fread($handleVar->toInt(), $lenVar->toInt());
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('fread() is not implemented for JIT in this compiler build');
    }
}
