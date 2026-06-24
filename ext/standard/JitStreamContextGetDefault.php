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

/** LLVM lowering for stream_context_get_default() (#6367, #9340). */
final class JitStreamContextGetDefault
{
    private const GLOBAL_DEFAULT = 'phpc_stream_context_default';

    private const GET_DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\StreamContextJitHelper::getDefault';

    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \LogicException(
                'stream_context_get_default() accepts at most one argument in this compiler build'
            );
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return self::invokeStandalone($context, $args);
        }

        return self::invokeEmbed($context, $args);
    }

    /** @param list<JITVariable> $args */
    private static function invokeEmbed(Context $context, array $args): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $optionsHt = $htPtrTy->constNull();
        if ([] !== $args) {
            $optionsHt = self::loadOptionalArrayArg($context, $args[0], 1);
        }

        StreamContextRuntime::ensureLinked($context);

        $ht = $context->builder->call(
            StreamContextRuntime::helperFunction($context, self::GET_DEFAULT_HELPER),
            $optionsHt
        );

        return self::wrapHashtableResult($context, $ht);
    }

    /** @param list<JITVariable> $args */
    private static function invokeStandalone(Context $context, array $args): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $optionsHt = $htPtrTy->constNull();
        if ([] !== $args) {
            $optionsHt = self::loadOptionalArrayArg($context, $args[0], 1);
        }

        StreamContextRuntime::ensureLinked($context);
        StreamContextRuntime::ensureDefaultGlobalDeclared($context);

        $fnCreate = $context->lookupFunction('__phpc_stream_context_create');
        $fnMerge = $context->lookupFunction('__phpc_stream_context_merge_options');
        $global = $context->module->getNamedGlobal(self::GLOBAL_DEFAULT);
        if (null === $global) {
            throw new \LogicException('StreamContextRuntime: '.self::GLOBAL_DEFAULT.' missing');
        }

        $nullHt = $htPtrTy->constNull();
        $defaultSlot = $context->builder->alloca($htPtrTy, 'scgd_default');
        $context->builder->store($context->builder->load($global), $defaultSlot);

        $initBb = BasicBlockHelper::append($context, 'scgd_init');
        $mergeCheckBb = BasicBlockHelper::append($context, 'scgd_merge_check');
        $mergeBb = BasicBlockHelper::append($context, 'scgd_merge');
        $doneBb = BasicBlockHelper::append($context, 'scgd_done');

        $needsInit = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($defaultSlot),
            $nullHt
        );
        $context->builder->branchIf($needsInit, $initBb, $mergeCheckBb);

        $context->builder->positionAtEnd($initBb);
        $created = $context->builder->call($fnCreate, $nullHt, $nullHt);
        $context->builder->store($created, $global);
        $context->builder->store($created, $defaultSlot);
        $context->builder->branch($mergeCheckBb);

        $context->builder->positionAtEnd($mergeCheckBb);
        $hasOptions = $context->builder->icmp(Builder::INT_NE, $optionsHt, $nullHt);
        $context->builder->branchIf($hasOptions, $mergeBb, $doneBb);

        $context->builder->positionAtEnd($mergeBb);
        $context->builder->call($fnMerge, $context->builder->load($defaultSlot), $optionsHt);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
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

    private static function loadOptionalArrayArg(Context $context, JITVariable $arg, int $position): Value
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
            "stream_context_get_default() argument #{$position} must be an array in this compiler build"
        );
    }
}
