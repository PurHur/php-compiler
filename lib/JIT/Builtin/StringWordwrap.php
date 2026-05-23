<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitWordwrap;
use PHPCompiler\JIT\Context;

/**
 * LLVM implementation of __string__wordwrap (mirrors VmString::wordwrap).
 */
final class StringWordwrap
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__wordwrap');
        $entry = $fn->appendBasicBlock('wordwrap_main');
        $context->builder->positionAtEnd($entry);

        JitWordwrap::resetBlockSerial();
        $result = JitWordwrap::buildWordwrapBody(
            $context,
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }
}
