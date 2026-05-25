<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/**
 * __compiler_ini_get LLVM body for non-standalone JIT (issue #1374, #1492).
 * Standalone AOT uses lib/AOT/runtime/phpc_ini_set.c.
 */
final class IniGet
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $fn = $context->lookupFunction('__compiler_ini_get');
        $entry = $fn->appendBasicBlock('ini_get_stub');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
