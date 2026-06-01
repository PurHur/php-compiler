<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * LLVM wrapper for __string__nl2br → __compiler_nl2br (php-src ext/standard/string.c parity).
 */
final class StringNl2br
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__nl2br');
        $entry = $fn->appendBasicBlock('nl2br_main');
        $context->builder->positionAtEnd($entry);

        $result = $context->builder->call(
            $context->lookupFunction('__compiler_nl2br'),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
    }
}
