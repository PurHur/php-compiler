<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_create() (#1377, #2457). */
final class JitStreamContextCreate
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity checked by stream_context_create::call via requireArgCountRangeJit (#30584).
        $argc = \count($args);

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtrTy->constNull();
        $optionsHt = $nullHt;
        if ($argc >= 1) {
            $optionsHt = self::loadArrayArg($context, $args[0], 1);
        }
        $paramsHt = $nullHt;
        if (2 === $argc) {
            $paramsHt = self::loadArrayArg($context, $args[1], 2);
        }

        StreamContextRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_stream_context_create'),
            $optionsHt,
            $paramsHt
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function loadArrayArg(Context $context, JITVariable $arg, int $position): Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return $context->getTypeFromString('__hashtable__*')->constNull();
        }
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \TypeError(self::arrayTypeErrorMessage($position, $arg->type));
    }

    private static function arrayTypeErrorMessage(int $position, int $type): string
    {
        $label = 1 === $position ? 'options' : 'params';
        $given = match ($type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };

        return \sprintf(
            'stream_context_create(): Argument #%d ($%s) must be of type ?array, %s given',
            $position,
            $label,
            $given
        );
    }
}
