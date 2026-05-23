<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * __compiler_is_superglobal_name() — native superglobal name test (VM + JIT/AOT, #1056).
 */
final class compiler_is_superglobal_name extends Internal
{
    public function __construct()
    {
        parent::__construct('__compiler_is_superglobal_name');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                '__compiler_is_superglobal_name() requires exactly one argument in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $name = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $name->type) {
            throw new \LogicException(
                '__compiler_is_superglobal_name() requires a string name in this compiler build'
            );
        }
        $frame->returnVar->bool(\PHPCompiler\Web\Superglobals::isSuperglobalNameVm($name->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException(
                '__compiler_is_superglobal_name() requires exactly one argument in this compiler build'
            );
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type && JITVariable::TYPE_VALUE !== $args[0]->type) {
            throw new \LogicException(
                '__compiler_is_superglobal_name() requires a string name in this compiler build'
            );
        }

        return JitSuperglobalName::invoke(
            $context,
            JitStringArg::lower($context, $args[0], '__compiler_is_superglobal_name() name')
        );
    }
}
