<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** function_exists() — whether a function is registered in this compile unit (issue #1216). */
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
        if (null === $frame->returnVar) {
            return;
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('function_exists() argument #1 must be a string in this compiler build');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('function_exists() requires VM context');
        }
        $lc = strtolower($nameVar->toString());
        $exists = isset($frame->vmContext->functions[$lc]);
        if (!$exists) {
            $short = substr($lc, strrpos($lc, '\\') + 1);
            $exists = isset($frame->vmContext->functions[$short]);
        }
        $frame->returnVar->bool($exists);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('function_exists() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type && JITVariable::TYPE_VALUE !== $args[0]->type) {
            throw new \LogicException('function_exists() argument #1 must be a string in this compiler build');
        }

        return JitFunctionExists::invoke($context, $args[0]);
    }
}
