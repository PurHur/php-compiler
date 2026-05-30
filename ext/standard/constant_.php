<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * constant() — resolve a global/user constant by name (issue #3813).
 *
 * php-src: ext/standard/basic_functions.c — zif_constant
 */
final class constant_ extends Internal
{
    public function __construct()
    {
        parent::__construct('constant');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('constant() requires exactly one argument');
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('constant() constant name must be a string');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('constant() requires VM context');
        }
        $name = $nameVar->toString();
        $value = $frame->vmContext->constantFetch($name);
        if (null !== $value) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->copyFrom($value);
            }

            return;
        }
        throw new \Error('Undefined constant "'.$name.'"');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('constant() requires exactly one argument');
        }

        return JitConstant::invoke($context, $args[0]);
    }
}
