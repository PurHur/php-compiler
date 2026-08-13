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
 * func_get_args() — current parameter values of the enclosing user function (#197, #21984).
 *
 * Excess argc → Zend ArgumentCountError (#30647; php-src Zend/zend_builtin_functions.c).
 */
final class func_get_args extends Internal
{
    public function __construct()
    {
        parent::__construct('func_get_args');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'func_get_args() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $args = VmReflection::userCallArgs($frame);
        } catch (\LogicException) {
            throw new \Error('func_get_args() cannot be called from the global scope');
        }
        $frame->returnVar->copyFrom(VmReflection::copyArgsToArray($args));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            // Catchable ArgumentCountError under AOT/JIT try/catch (#30647).
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'func_get_args() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $packed = JitFuncArgs::getArgs($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $context->helper->loadValue($packed)
        );

        return $ptr;
    }
}
