<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for header_remove() and header_list() (issue #311).
 */
final class JitPendingHeaders
{
    public static function remove(Context $context, ?JITVariable $name = null): void
    {
        if (null === $name) {
            $empty = $context->builder->load($context->constantStringFromString(''));
            $context->builder->call(
                $context->lookupFunction('__phpc_pending_header_remove'),
                $empty
            );

            return;
        }
        if (JITVariable::TYPE_STRING === $name->type) {
            $context->builder->call(
                $context->lookupFunction('__phpc_pending_header_remove'),
                $context->helper->loadValue($name)
            );

            return;
        }
        if (JITVariable::TYPE_VALUE === $name->type) {
            $str = $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $name->value
            );
            $context->builder->call(
                $context->lookupFunction('__phpc_pending_header_remove'),
                $str
            );

            return;
        }

        throw new \LogicException('header_remove() name must be a string in this compiler build');
    }

    public static function list(Context $context): Value
    {
        return $context->builder->call($context->lookupFunction('__phpc_pending_header_list'));
    }

    public static function add(Context $context, Value $linePtr, Value $replaceI32): void
    {
        $context->builder->call(
            $context->lookupFunction('__phpc_pending_header_add'),
            $linePtr,
            $replaceI32
        );
    }
}
