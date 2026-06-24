<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT bridge for function-static init flags (#10173).
 *
 * Init-once bits live in module global {@see self::INIT_TABLE_GLOBAL} (LLVM i8 table).
 * VM SSOT: {@see \PHPCompiler\VM\VmFunctionStatic} + {@see \PHPCompiler\VM\Context}.
 *
 * Nested PHP helper compile is blocked until JIT supports static property stores in helpers;
 * table ABI keeps init policy out of {@see \PHPCompiler\JIT\FunctionStaticHelper}.
 */
final class FunctionStaticRuntime
{
    public const INIT_TABLE_GLOBAL = 'phpc_fn_static_init_table';

    private const MAX_SLOTS = 1024;

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        'phpc_fn_static_is_initialized',
        'phpc_fn_static_mark_initialized',
    ];

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureInitTableGlobal($context);

        $probe = $context->module->getNamedFunction('phpc_fn_static_is_initialized');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::implementIsInitializedBridge($context);
        self::implementMarkInitializedBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureInitTableGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::INIT_TABLE_GLOBAL)) {
            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $tableTy = $i8->arrayType(self::MAX_SLOTS);
        $global = $context->module->addGlobal($tableTy, self::INIT_TABLE_GLOBAL);
        $global->setInitializer($tableTy->constNull());
    }

    private static function implementIsInitializedBridge(Context $context): void
    {
        $abiName = 'phpc_fn_static_is_initialized';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($i1, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('fn_static_is_init_entry');
        $oobBb = $fn->appendBasicBlock('fn_static_is_init_oob');
        $loadBb = $fn->appendBasicBlock('fn_static_is_init_load');
        $doneBb = $fn->appendBasicBlock('fn_static_is_init_done');
        $context->builder->positionAtEnd($entry);

        $idx = $fn->getParam(0);
        $max = $i64->constInt(self::MAX_SLOTS, false);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $idx, $max);
        $context->builder->branchIf($inRange, $loadBb, $oobBb);

        $context->builder->positionAtEnd($oobBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loadBb);
        $table = $context->module->getNamedGlobal(self::INIT_TABLE_GLOBAL);
        $zero = $i64->constInt(0, false);
        $slotPtr = $context->builder->gep($table, $zero, $idx);
        $loaded = $context->builder->load($slotPtr);
        $isSet = $context->builder->icmp(
            Builder::INT_NE,
            $loaded,
            $i8->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(0, false), $oobBb);
        $phi->addIncoming($isSet, $loadBb);
        $context->builder->returnValue($phi);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementMarkInitializedBridge(Context $context): void
    {
        $abiName = 'phpc_fn_static_mark_initialized';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('fn_static_mark_init_entry');
        $storeBb = $fn->appendBasicBlock('fn_static_mark_init_store');
        $doneBb = $fn->appendBasicBlock('fn_static_mark_init_done');
        $context->builder->positionAtEnd($entry);

        $idx = $fn->getParam(0);
        $max = $i64->constInt(self::MAX_SLOTS, false);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $idx, $max);
        $context->builder->branchIf($inRange, $storeBb, $doneBb);

        $context->builder->positionAtEnd($storeBb);
        $table = $context->module->getNamedGlobal(self::INIT_TABLE_GLOBAL);
        $zero = $i64->constInt(0, false);
        $slotPtr = $context->builder->gep($table, $zero, $idx);
        $context->builder->store($i8->constInt(1, false), $slotPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after FunctionStaticRuntime bridge (#10173)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
