<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** password_verify() — VM only (issue #172). */
final class password_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('password_verify');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('password_verify() requires exactly two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $password = $frame->calledArgs[0]->resolveIndirect();
        $hash = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $password->type || Variable::TYPE_STRING !== $hash->type) {
            throw new \LogicException('password_verify() requires string password and hash in this compiler build');
        }
        $frame->returnVar->bool(
            VmPassword::verify($password->toString(), $hash->toString())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('password_verify() is not implemented for JIT in this compiler build');
    }
}
