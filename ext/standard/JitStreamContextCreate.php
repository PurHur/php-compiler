<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_create() (#1377). */
final class JitStreamContextCreate
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            throw new \LogicException(
                'stream_context_create() options are not supported for JIT in this compiler build (issue #1377)'
            );
        }

        $packed = HashTableHelper::emptyVariable($context);
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
