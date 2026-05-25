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

        $ht = HashTableHelper::alloc($context);
        $markerKey = $context->builder->load(
            $context->constantStringFromString(VmStreamContext::MARKER_KEY)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $markerKey,
            $context->getTypeFromString('int64')->constInt(1, false)
        );

        $packed = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $ht
        );
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
