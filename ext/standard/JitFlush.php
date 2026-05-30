<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for flush() (issue #3388). */
final class JitFlush
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('flush() takes no arguments');
        }
        $context->builder->call($context->lookupFunction('__phpc_flush'));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
