<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** func_num_args() — count of arguments passed to the current user function (issue #197). */
final class func_num_args extends Internal
{
    public function __construct()
    {
        parent::__construct('func_num_args');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('func_num_args() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $args = VmReflection::userCallArgs($frame);
        $frame->returnVar->int(\count($args));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('func_num_args() takes no arguments');
        }

        return JitFuncArgs::numArgs($context);
    }
}
