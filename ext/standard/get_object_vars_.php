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
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'get_object_vars() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(
            VmReflection::getObjectVars($frame->calledArgs[0], $frame)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'get_object_vars() expects exactly 1 argument, %d given',
                $argc
            ));
        }

        return JitGetObjectVars::invoke($context, $args[0]);
    }
}
