<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * LLVM + MCJIT hooks for return-through-finally in JIT (issue #4246).
 */
final class JitReturnPending
{
    /** @var array<int, true> */
    private static array $globalsRegisteredForModule = [];

    public static function ensureLinked(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        self::implement($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');

        $decls = [
            'phpc_jit_clear_return_pending' => [$void, false, []],
            'phpc_jit_has_return_pending' => [$i32, false, []],
            'phpc_jit_return_pending_is_void' => [$i32, false, []],
            'phpc_jit_set_return_pending' => [$void, false, [$valuePtr, $i32]],
            'phpc_jit_take_return_pending' => [$valuePtr, false, []],
        ];
        foreach ($decls as $name => [$ret, $vararg, $params]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    public static function setPending(Context $context, ?Variable $returnVar, bool $isVoid): void
    {
        self::registerDeclarations($context);
        self::ensureLinked($context);
        $builder = $context->builder;
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        if ($isVoid) {
            $builder->call(
                $context->lookupFunction('phpc_jit_set_return_pending'),
                $valuePtr->constNull(),
                $i32->constInt(1, false)
            );

            return;
        }
        if (null === $returnVar) {
            throw new \LogicException('JIT return-through-finally requires a return value');
        }
        $ptr = JitValueBox::valuePtrFromVariable($context, $returnVar);
        $builder->call(
            $context->lookupFunction('phpc_jit_set_return_pending'),
            $ptr,
            $i32->constInt(0, false)
        );
    }

    private static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        self::registerPendingGlobals($context);
        self::implementPendingHelpers($context);
    }

    private static function registerPendingGlobals(Context $context): void
    {
        $moduleId = spl_object_id($context->module);
        if (isset(self::$globalsRegisteredForModule[$moduleId])) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        if (null === $context->module->getNamedGlobal('phpc_jit_return_flag')) {
            $flag = $context->module->addGlobal($i8, 'phpc_jit_return_flag');
            $flag->setInitializer($i8->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal('phpc_jit_return_void_flag')) {
            $voidFlag = $context->module->addGlobal($i8, 'phpc_jit_return_void_flag');
            $voidFlag->setInitializer($i8->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal('phpc_jit_return_val_i64')) {
            $val = $context->module->addGlobal($i64, 'phpc_jit_return_val_i64');
            $val->setInitializer($i64->constInt(0, false));
        }
        self::$globalsRegisteredForModule[$moduleId] = true;
    }

    private static function implementPendingHelpers(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');

        if (null === $context->module->getNamedFunction('phpc_jit_clear_return_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_clear_return_pending')->countBasicBlocks()
        ) {
            $clear = $context->lookupFunction('phpc_jit_clear_return_pending');
            $block = $clear->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $flag = $context->module->getNamedGlobal('phpc_jit_return_flag');
            $voidFlag = $context->module->getNamedGlobal('phpc_jit_return_void_flag');
            $valGlobal = $context->module->getNamedGlobal('phpc_jit_return_val_i64');
            $context->builder->store($i8->constInt(0, false), $context->builder->pointerCast($flag, $i8p));
            $context->builder->store($i8->constInt(0, false), $context->builder->pointerCast($voidFlag, $i8p));
            $context->builder->store($i64->constInt(0, false), $valGlobal);
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_has_return_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_has_return_pending')->countBasicBlocks()
        ) {
            $has = $context->lookupFunction('phpc_jit_has_return_pending');
            $block = $has->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $flag = $context->module->getNamedGlobal('phpc_jit_return_flag');
            $loaded = $context->builder->load($context->builder->pointerCast($flag, $i8p));
            $context->builder->returnValue($context->builder->zext($loaded, $i32));
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_return_pending_is_void')
            || 0 === $context->module->getNamedFunction('phpc_jit_return_pending_is_void')->countBasicBlocks()
        ) {
            $isVoidFn = $context->lookupFunction('phpc_jit_return_pending_is_void');
            $block = $isVoidFn->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $voidFlag = $context->module->getNamedGlobal('phpc_jit_return_void_flag');
            $loaded = $context->builder->load($context->builder->pointerCast($voidFlag, $i8p));
            $context->builder->returnValue($context->builder->zext($loaded, $i32));
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_set_return_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_set_return_pending')->countBasicBlocks()
        ) {
            $set = $context->lookupFunction('phpc_jit_set_return_pending');
            $block = $set->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $valParam = $set->getParam(0);
            $voidParam = $set->getParam(1);
            $flag = $context->module->getNamedGlobal('phpc_jit_return_flag');
            $voidFlag = $context->module->getNamedGlobal('phpc_jit_return_void_flag');
            $valGlobal = $context->module->getNamedGlobal('phpc_jit_return_val_i64');
            $addr = $context->builder->ptrToInt($valParam, $i64);
            $context->builder->store($addr, $valGlobal);
            $voidByte = $context->builder->trunc($voidParam, $i8);
            $context->builder->store($voidByte, $context->builder->pointerCast($voidFlag, $i8p));
            $context->builder->store($i8->constInt(1, false), $context->builder->pointerCast($flag, $i8p));
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_take_return_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_take_return_pending')->countBasicBlocks()
        ) {
            $take = $context->lookupFunction('phpc_jit_take_return_pending');
            $entry = $take->appendBasicBlock('entry');
            $done = $take->appendBasicBlock('done');
            $context->builder->positionAtEnd($entry);
            $flag = $context->module->getNamedGlobal('phpc_jit_return_flag');
            $valGlobal = $context->module->getNamedGlobal('phpc_jit_return_val_i64');
            $flagLoaded = $context->builder->load($context->builder->pointerCast($flag, $i8p));
            $has = $context->builder->icmp(PHPLLVM\Builder::INT_NE, $flagLoaded, $i8->constInt(0, false));
            $loadBlock = $take->appendBasicBlock('load');
            $nullBlock = $take->appendBasicBlock('null');
            $context->builder->branchIf($has, $loadBlock, $nullBlock);
            $context->builder->positionAtEnd($loadBlock);
            $addr = $context->builder->load($valGlobal);
            $loaded = $context->builder->intToPtr($addr, $valuePtr);
            $context->builder->store($i8->constInt(0, false), $context->builder->pointerCast($flag, $i8p));
            $context->builder->store($i64->constInt(0, false), $valGlobal);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nullBlock);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
            $phi = $context->builder->phi($valuePtr);
            $phi->addIncoming($loaded, $loadBlock);
            $phi->addIncoming($valuePtr->constNull(), $nullBlock);
            $context->builder->returnValue($phi);
            $context->builder->clearInsertionPosition();
        }
    }
}
