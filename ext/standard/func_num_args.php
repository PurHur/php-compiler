<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * func_num_args() — count of arguments passed to the current user function (issue #197).
 *
 * Excess argc → Zend ArgumentCountError (#30647; php-src Zend/zend_builtin_functions.c).
 */
final class func_num_args extends Internal
{
    public function __construct()
    {
        parent::__construct('func_num_args');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'func_num_args() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $args = VmReflection::userCallArgs($frame);
        } catch (\LogicException) {
            // php-src: "func_num_args() must be called from a function context"
            throw new \Error('func_num_args() must be called from a function context');
        }
        $frame->returnVar->int(\count($args));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            // Catchable ArgumentCountError under AOT/JIT try/catch (#30647).
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'func_num_args() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitFuncArgs::numArgs($context);
    }
}
