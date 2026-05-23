<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** function_exists() — whether a function is registered (issue #1216). */
final class function_exists extends Internal
{
    public function __construct()
    {
        parent::__construct('function_exists');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('function_exists() requires exactly one argument');
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('function_exists() function name must be a string');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('function_exists() requires VM context');
        }
        $exists = $frame->vmContext->functionIsRegistered($nameVar->toString());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('function_exists() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'function_exists() function name');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || null === $args[0]->compileTimeString) {
            throw new \LogicException(
                'function_exists() function name must be a string literal in this compiler build'
            );
        }
        // User functions are registered on the VM at runtime only; JIT checks builtins + already-declared names.
        $exists = $context->runtime->vmContext->functionIsRegistered($args[0]->compileTimeString);
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt($exists ? 1 : 0, false);
    }
}
