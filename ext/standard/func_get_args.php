<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** func_get_args() — current parameter values of the enclosing user function (#197, #21984). */
final class func_get_args extends Internal
{
    public function __construct()
    {
        parent::__construct('func_get_args');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('func_get_args() takes no arguments');
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
        if (\count($args) > 0) {
            throw new \LogicException('func_get_args() takes no arguments');
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
