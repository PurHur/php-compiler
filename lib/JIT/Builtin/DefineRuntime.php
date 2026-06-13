<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Runtime user constants for define()/defined()/constant() (issue #4435).
 *
 * PHP SSOT: {@see \PHPCompiler\VM\Context::defineConstant}. MCJIT/AOT store dynamic
 * registrations in {@see GLOBAL} instead of FUNCCALL VM fallback.
 */
final class DefineRuntime
{
    public const GLOBAL = 'phpc_user_constants';

    private static int $blockSeq = 0;

    public static function ensureLinked(Context $context): void
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        if (null === $context->module->getNamedGlobal(self::GLOBAL)) {
            $context->module->addGlobal($htPtrTy, self::GLOBAL)->setInitializer($htPtrTy->constNull());
        }
    }

    public static function emitDefine(Context $context, Value $nameStr, JITVariable $value): Value
    {
        self::ensureLinked($context);
        $ht = self::loadUserConstantsTable($context);
        $exists = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $nameStr
        );
        $tag = 'def'.(string) ++self::$blockSeq;
        $fail = BasicBlockHelper::append($context, 'user_const_def_fail_'.$tag);
        $store = BasicBlockHelper::append($context, 'user_const_def_store_'.$tag);
        $done = BasicBlockHelper::append($context, 'user_const_def_done_'.$tag);
        $context->builder->branchIf($exists, $fail, $store);

        $context->builder->positionAtEnd($fail);
        $failEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($store);
        HashTableHelper::setAtStringKey($context, $ht, $nameStr, $value);
        $storeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $failEnd);
        $phi->addIncoming($i1->constInt(1, false), $storeEnd);

        return $phi;
    }

    public static function emitDefined(Context $context, Value $nameStr): Value
    {
        self::ensureLinked($context);
        $ht = self::loadUserConstantsTable($context);

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $nameStr
        );
    }

    public static function loadTable(Context $context): Value
    {
        self::ensureLinked($context);

        return self::loadUserConstantsTable($context);
    }

    private static function loadUserConstantsTable(Context $context): Value
    {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $global = $context->module->getNamedGlobal(self::GLOBAL);
        if (null === $global) {
            self::ensureLinked($context);
            $global = $context->module->getNamedGlobal(self::GLOBAL);
        }
        $cur = $context->builder->load($global);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $cur, $htPtrTy->constNull());
        $tag = 'ht'.(string) ++self::$blockSeq;
        $entry = $context->builder->getInsertBlock();
        $init = BasicBlockHelper::append($context, 'user_const_ht_init_'.$tag);
        $ready = BasicBlockHelper::append($context, 'user_const_ht_ready_'.$tag);
        $context->builder->branchIf($isNull, $init, $ready);

        $context->builder->positionAtEnd($init);
        $ht = HashTableHelper::alloc($context);
        self::seedCompileTimeUserConstants($context, $ht);
        $context->builder->store($ht, $global);
        $initEnd = $context->builder->getInsertBlock();
        $context->builder->branch($ready);

        $context->builder->positionAtEnd($ready);
        $phi = $context->builder->phi($htPtrTy);
        $phi->addIncoming($ht, $initEnd);
        $phi->addIncoming($cur, $entry);

        return $phi;
    }

    private static function seedCompileTimeUserConstants(Context $context, Value $ht): void
    {
        $vmContext = $context->runtime->vmContext;
        if (null === $vmContext || [] === $vmContext->constants) {
            return;
        }
        foreach ($vmContext->constants as $name => $vmVar) {
            $resolved = $vmVar->resolveIndirect();
            $key = $context->builder->load($context->constantStringFromString($name));
            $element = self::jitVariableFromVm($context, $resolved);
            if (null === $element) {
                continue;
            }
            HashTableHelper::setAtStringKey($context, $ht, $key, $element);
        }
    }

    private static function jitVariableFromVm(Context $context, \PHPCompiler\VM\Variable $value): ?JITVariable
    {
        switch ($value->type) {
            case \PHPCompiler\VM\Variable::TYPE_INTEGER:
                return new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt($value->toInt(), false)
                );
            case \PHPCompiler\VM\Variable::TYPE_BOOLEAN:
                return new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt($value->toBool() ? 1 : 0, false)
                );
            case \PHPCompiler\VM\Variable::TYPE_FLOAT:
                return new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_DOUBLE,
                    JITVariable::KIND_VALUE,
                    $context->constantFromFloat($value->toFloat())
                );
            case \PHPCompiler\VM\Variable::TYPE_STRING:
                return new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString($value->toString()))
                );
            case \PHPCompiler\VM\Variable::TYPE_NULL:
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    \PHPCompiler\JIT\JitValueBox::pointer($context, $slot)
                );

                return new JITVariable(
                    $context,
                    JITVariable::TYPE_VALUE,
                    JITVariable::KIND_VARIABLE,
                    $slot
                );
            default:
                return null;
        }
    }
}
