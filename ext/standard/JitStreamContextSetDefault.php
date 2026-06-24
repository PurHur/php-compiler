<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_context_set_default() (#6367, #9340). */
final class JitStreamContextSetDefault
{
    private const GLOBAL_DEFAULT = 'phpc_stream_context_default';

    private const SET_DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::setDefault';

    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException(
                'stream_context_set_default() requires exactly one argument in this compiler build'
            );
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return self::invokeStandalone($context, $args[0]);
        }

        return self::invokeEmbed($context, $args[0]);
    }

    private static function invokeEmbed(Context $context, JITVariable $arg): Value
    {
        $optionsHt = self::loadArrayArg($context, $arg, 1);

        StreamContextRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            StreamContextRuntime::helperFunction($context, self::SET_DEFAULT_HELPER),
            $optionsHt
        );

        return self::wrapHashtableResult($context, $ht);
    }

    private static function invokeStandalone(Context $context, JITVariable $arg): Value
    {
        $optionsHt = self::loadArrayArg($context, $arg, 1);

        StreamContextRuntime::ensureLinked($context);
        StreamContextRuntime::ensureDefaultGlobalDeclared($context);

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $fnCreate = $context->lookupFunction('__phpc_stream_context_create');
        $fnMerge = $context->lookupFunction('__phpc_stream_context_merge_options');
        $global = $context->module->getNamedGlobal(self::GLOBAL_DEFAULT);
        if (null === $global) {
            throw new \LogicException('StreamContextRuntime: '.self::GLOBAL_DEFAULT.' missing');
        }

        $nullHt = $htPtrTy->constNull();
        $defaultSlot = $context->builder->alloca($htPtrTy, 'scsd_default');
        $context->builder->store($context->builder->load($global), $defaultSlot);

        $initBb = BasicBlockHelper::append($context, 'scsd_init');
        $mergeBb = BasicBlockHelper::append($context, 'scsd_merge');

        $needsInit = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($defaultSlot),
            $nullHt
        );
        $context->builder->branchIf($needsInit, $initBb, $mergeBb);

        $context->builder->positionAtEnd($initBb);
        $created = $context->builder->call($fnCreate, $nullHt, $nullHt);
        $context->builder->store($created, $global);
        $context->builder->store($created, $defaultSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $context->builder->call($fnMerge, $context->builder->load($defaultSlot), $optionsHt);
        $ht = $context->builder->load($defaultSlot);

        return self::wrapHashtableResult($context, $ht);
    }

    private static function wrapHashtableResult(Context $context, Value $ht): Value
    {
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
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $arg->value)
            );
        }

        throw new \LogicException(
            "stream_context_set_default() argument #{$position} must be an array in this compiler build"
        );
    }
}
