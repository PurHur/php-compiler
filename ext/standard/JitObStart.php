<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_start() (issue #118, #1056). */
final class JitObStart
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('ob_start() callback arguments not supported in this compiler build');
        }
        $context->builder->call($context->lookupFunction('__phpc_ob_start'));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
