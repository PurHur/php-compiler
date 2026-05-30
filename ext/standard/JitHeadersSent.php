<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for headers_sent() (issue #3120). */
final class JitHeadersSent
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] !== $args) {
            throw new \LogicException(
                'headers_sent() JIT/AOT only supports zero arguments in this compiler build; use bin/vm.php for &$file and &$line'
            );
        }
        $i32 = $context->getTypeFromString('int32');
        $sent = $context->builder->call($context->lookupFunction('__phpc_headers_sent'));

        return $context->builder->icmp(Builder::INT_NE, $sent, $i32->constInt(0, false));
    }
}
