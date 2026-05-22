<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** defined() — whether a user constant is registered (issue #204). */
final class defined_ extends Internal
{
    public function __construct()
    {
        parent::__construct('defined');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('defined() requires exactly one argument');
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('defined() constant name must be a string');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('defined() requires VM context');
        }
        $defined = null !== $frame->vmContext->constantFetch($nameVar->toString());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($defined);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== count($args)) {
            throw new \LogicException('defined() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || null === $args[0]->compileTimeString) {
            throw new \LogicException('defined() constant name must be a string literal in this compiler build');
        }
        $defined = null !== $context->runtime->vmContext->constantFetch($args[0]->compileTimeString);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($defined ? 1 : 0, false);
    }
}
