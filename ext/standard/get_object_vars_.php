<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** get_object_vars() — accessible instance properties as an array (issue #1370). */
final class get_object_vars_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_object_vars');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_object_vars() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(
            VmReflection::getObjectVars($frame->calledArgs[0])
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_object_vars() requires exactly one argument');
        }

        return JitGetObjectVars::invoke($context, $args[0]);
    }
}
