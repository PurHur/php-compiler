<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_get_level() (issue #118, #1056). */
final class JitObGetLevel
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('ob_get_level() takes no arguments');
        }
        $level = $context->builder->call($context->lookupFunction('__phpc_ob_get_level'));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptr,
            $context->builder->zExt($level, $i64)
        );

        return $ptr;
    }
}
