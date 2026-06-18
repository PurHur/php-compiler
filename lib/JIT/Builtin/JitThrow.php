<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM;

/**
 * LLVM + MCJIT hooks for JIT try/catch object throws (issues #57, #195, #1056).
 */
final class JitThrow
{
    private static ?int $clearAddress = null;

    private static ?int $hasAddress = null;

    private static ?int $takeAddress = null;

    /** @var array<int, true> module object id => registered */
    private static array $globalsRegisteredForModule = [];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** LLVM bodies for standalone AOT (replaces phpc_jit_throw.c — #5724). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::registerPendingGlobals($context);
        self::registerDeclarations($context);
        self::implementPendingHelpers($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::registerPendingGlobals($context);
            self::implementPendingHelpers($context);

            return;
        }

        ExceptionThrowRuntime::implement($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        $objPtr = $context->getTypeFromString('__object__*');

        $decls = [
            'phpc_jit_clear_throw_pending' => [$void, false, []],
            'phpc_jit_has_throw_pending' => [$i32, false, []],
            'phpc_jit_set_throw_pending' => [$void, false, [$objPtr]],
            'phpc_jit_take_throw_pending' => [$objPtr, false, []],
            'phpc_jit_clear_active_catch' => [$void, false, []],
            'phpc_jit_get_active_catch' => [$objPtr, false, []],
            'phpc_jit_set_active_catch' => [$void, false, [$objPtr]],
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

    private static function registerPendingGlobals(Context $context): void
    {
        $moduleId = spl_object_id($context->module);
        if (isset(self::$globalsRegisteredForModule[$moduleId])) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        if (null === $context->module->getNamedGlobal('phpc_jit_throw_flag')) {
            $flag = $context->module->addGlobal($i8, 'phpc_jit_throw_flag');
            $flag->setInitializer($i8->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal('phpc_jit_throw_obj_i64')) {
            $obj = $context->module->addGlobal($i64, 'phpc_jit_throw_obj_i64');
            $obj->setInitializer($i64->constInt(0, false));
        }
        if (null === $context->module->getNamedGlobal('phpc_jit_active_catch_i64')) {
            $active = $context->module->addGlobal($i64, 'phpc_jit_active_catch_i64');
            $active->setInitializer($i64->constInt(0, false));
        }
        self::$globalsRegisteredForModule[$moduleId] = true;
    }

    private static function implementPendingHelpers(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $objPtr = $context->getTypeFromString('__object__*');

        if (null === $context->module->getNamedFunction('phpc_jit_clear_throw_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_clear_throw_pending')->countBasicBlocks()
        ) {
            $clear = $context->lookupFunction('phpc_jit_clear_throw_pending');
            $block = $clear->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $flag = $context->module->getNamedGlobal('phpc_jit_throw_flag');
            $objGlobal = $context->module->getNamedGlobal('phpc_jit_throw_obj_i64');
            $context->builder->store($i8->constInt(0, false), $context->builder->pointerCast($flag, $i8p));
            $context->builder->store($i64->constInt(0, false), $objGlobal);
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_has_throw_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_has_throw_pending')->countBasicBlocks()
        ) {
            $has = $context->lookupFunction('phpc_jit_has_throw_pending');
            $block = $has->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $flag = $context->module->getNamedGlobal('phpc_jit_throw_flag');
            $loaded = $context->builder->load($context->builder->pointerCast($flag, $i8p));
            $context->builder->returnValue($context->builder->zext($loaded, $i32));
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_set_throw_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_set_throw_pending')->countBasicBlocks()
        ) {
            $set = $context->lookupFunction('phpc_jit_set_throw_pending');
            $block = $set->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $obj = $set->getParam(0);
            $flag = $context->module->getNamedGlobal('phpc_jit_throw_flag');
            $objGlobal = $context->module->getNamedGlobal('phpc_jit_throw_obj_i64');
            $addr = $context->builder->ptrToInt($obj, $i64);
            $context->builder->store($addr, $objGlobal);
            $context->builder->store($i8->constInt(1, false), $context->builder->pointerCast($flag, $i8p));
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_take_throw_pending')
            || 0 === $context->module->getNamedFunction('phpc_jit_take_throw_pending')->countBasicBlocks()
        ) {
            $take = $context->lookupFunction('phpc_jit_take_throw_pending');
            $entry = $take->appendBasicBlock('entry');
            $done = $take->appendBasicBlock('done');
            $context->builder->positionAtEnd($entry);
            $flag = $context->module->getNamedGlobal('phpc_jit_throw_flag');
            $objGlobal = $context->module->getNamedGlobal('phpc_jit_throw_obj_i64');
            $flagLoaded = $context->builder->load($context->builder->pointerCast($flag, $i8p));
            $has = $context->builder->icmp(PHPLLVM\Builder::INT_NE, $flagLoaded, $i8->constInt(0, false));
            $loadBlock = $take->appendBasicBlock('load');
            $nullBlock = $take->appendBasicBlock('null');
            $context->builder->branchIf($has, $loadBlock, $nullBlock);
            $context->builder->positionAtEnd($loadBlock);
            $addr = $context->builder->load($objGlobal);
            $loaded = $context->builder->intToPtr($addr, $objPtr);
            $context->builder->store($i8->constInt(0, false), $context->builder->pointerCast($flag, $i8p));
            $context->builder->store($i64->constInt(0, false), $objGlobal);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nullBlock);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);
            $phi = $context->builder->phi($objPtr);
            $phi->addIncoming($loaded, $loadBlock);
            $phi->addIncoming($objPtr->constNull(), $nullBlock);
            $context->builder->returnValue($phi);
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_clear_active_catch')
            || 0 === $context->module->getNamedFunction('phpc_jit_clear_active_catch')->countBasicBlocks()
        ) {
            $clear = $context->lookupFunction('phpc_jit_clear_active_catch');
            $block = $clear->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $active = $context->module->getNamedGlobal('phpc_jit_active_catch_i64');
            $context->builder->store($i64->constInt(0, false), $active);
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_get_active_catch')
            || 0 === $context->module->getNamedFunction('phpc_jit_get_active_catch')->countBasicBlocks()
        ) {
            $get = $context->lookupFunction('phpc_jit_get_active_catch');
            $block = $get->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $active = $context->module->getNamedGlobal('phpc_jit_active_catch_i64');
            $addr = $context->builder->load($active);
            $loaded = $context->builder->intToPtr($addr, $objPtr);
            $context->builder->returnValue($loaded);
            $context->builder->clearInsertionPosition();
        }

        if (null === $context->module->getNamedFunction('phpc_jit_set_active_catch')
            || 0 === $context->module->getNamedFunction('phpc_jit_set_active_catch')->countBasicBlocks()
        ) {
            $set = $context->lookupFunction('phpc_jit_set_active_catch');
            $block = $set->appendBasicBlock('entry');
            $context->builder->positionAtEnd($block);
            $obj = $set->getParam(0);
            $active = $context->module->getNamedGlobal('phpc_jit_active_catch_i64');
            $context->builder->store($context->builder->ptrToInt($obj, $i64), $active);
            $context->builder->returnVoid();
            $context->builder->clearInsertionPosition();
        }
    }

    public static function bindJitEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        self::$clearAddress = $engine->getFunctionAddress('phpc_jit_clear_throw_pending');
        self::$hasAddress = $engine->getFunctionAddress('phpc_jit_has_throw_pending');
        self::$takeAddress = $engine->getFunctionAddress('phpc_jit_take_throw_pending');
    }

    public static function clearPendingAtRunEntry(): void
    {
        if (null === self::$clearAddress || 0 === self::$clearAddress) {
            return;
        }
        $cb = self::callableFromAddress('void(*)()', self::$clearAddress);
        $cb();
    }

    public static function throwPendingIfAny(): void
    {
        if (null === self::$hasAddress || 0 === self::$hasAddress
            || null === self::$takeAddress || 0 === self::$takeAddress
        ) {
            return;
        }
        $has = self::callableFromAddress('int(*)()', self::$hasAddress);
        if (0 === $has()) {
            return;
        }
        throw new \Exception('Uncaught exception in JIT');
    }

    /**
     * @return callable
     */
    private static function callableFromAddress(string $ctype, int $address): callable
    {
        $code = \FFI::new('uintptr_t');
        $code->cdata = $address;
        $cb = \FFI::new($ctype);
        \FFI::memcpy(\FFI::addr($cb), \FFI::addr($code), \FFI::sizeof($cb));

        return $cb;
    }
}
