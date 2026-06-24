<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_getenv_all — zero-arg getenv() assoc table (#5075 phase 2).
 *
 * php-src: ext/standard/basic_functions.c — zif_getenv argc==0; mirrors
 * {@see \PHPCompiler\ext\standard\VmEnv::getAllEnvironmentMap()}.
 */
final class StringGetenvAll
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_getenv_all');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        EnvLocalRuntime::ensureLinked($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);
        self::ensureEnvironGlobal($context);

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_getenv_all', $ft);
        self::implementGetenvAll($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementGetenvAll(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ga_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtrTy->constNull());

        $nullOutBb = $fn->appendBasicBlock('ga_null_out');
        $bodyBb = $fn->appendBasicBlock('ga_body');
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($bodyBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $allocFailBb = $fn->appendBasicBlock('ga_alloc_fail');
        $fillBb = $fn->appendBasicBlock('ga_fill');
        $context->builder->branchIf($htNull, $allocFailBb, $fillBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($fillBb);
        self::emitEnvironWalk($context, $fn, $ht);
        EnvLocalRuntime::emitMergeOverlay($context, $ht);

        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitEnvironWalk(Context $context, LlvmFunction $fn, Value $ht): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $environGlobal = $context->module->getNamedGlobal('environ');
        if (null === $environGlobal) {
            return;
        }

        $envPtr = $context->builder->load(
            $context->builder->pointerCast($environGlobal, $i8pp->pointerType(0))
        );
        $envSlot = BasicBlockHelper::entryAlloca($context, $i8pp);
        $context->builder->store($envPtr, $envSlot);

        $loopHead = $fn->appendBasicBlock('ga_env_head');
        $loopBody = $fn->appendBasicBlock('ga_env_body');
        $doneBb = $fn->appendBasicBlock('ga_env_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $env = $context->builder->load($envSlot);
        $envNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8pp->constNull());
        $entryNull = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($env),
            $i8p->constNull()
        );
        $stop = $context->builder->or($envNull, $entryNull);
        $context->builder->branchIf($stop, $doneBb, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $line = $context->builder->load($env);
        $eq = $context->builder->call(
            $context->lookupFunction('strchr'),
            $line,
            $i32->constInt(61, false)
        );
        $noEqBb = $fn->appendBasicBlock('ga_env_no_eq');
        $haveEqBb = $fn->appendBasicBlock('ga_env_have_eq');
        $eqNull = $context->builder->icmp(Builder::INT_EQ, $eq, $i8p->constNull());
        $context->builder->branchIf($eqNull, $noEqBb, $haveEqBb);

        $context->builder->positionAtEnd($haveEqBb);
        $keyLen = $context->builder->sub(
            $context->builder->ptrToInt($eq, $i64),
            $context->builder->ptrToInt($line, $i64)
        );
        $emptyKeyBb = $fn->appendBasicBlock('ga_env_empty_key');
        $setBb = $fn->appendBasicBlock('ga_env_set');
        $keyEmpty = $context->builder->icmp(Builder::INT_EQ, $keyLen, $i64->constInt(0, false));
        $context->builder->branchIf($keyEmpty, $emptyKeyBb, $setBb);

        $context->builder->positionAtEnd($setBb);
        $value = $context->builder->inBoundsGEP($eq, $i64->constInt(1, false));
        self::setBoundedKeyCstrPair($context, $ht, $line, $keyLen, $value);
        $context->builder->branch($noEqBb);

        $context->builder->positionAtEnd($emptyKeyBb);
        $context->builder->branch($noEqBb);

        $context->builder->positionAtEnd($noEqBb);
        $context->builder->store(
            $context->builder->inBoundsGEP($env, $i64->constInt(1, false)),
            $envSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function setBoundedKeyCstrPair(
        Context $context,
        Value $ht,
        Value $keyCstr,
        Value $keyLen,
        Value $valueCstr
    ): void {
        $keyStr = self::cstrToStringWithLength($context, $keyCstr, $keyLen);
        $valStr = self::cstrToString($context, $valueCstr);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
    }

    private static function cstrToStringWithLength(Context $context, Value $cstr, Value $lenI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $context->builder->pointerCast($cstr, $charPtr)
        );
    }

    private static function ensureEnvironGlobal(Context $context): void
    {
        if (null === $context->module->getNamedGlobal('environ')) {
            $context->module->addGlobal($context->getTypeFromString('int8**'), 'environ');
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal($context, 'strchr', $context->context->functionType($i8p, false, $i8p, $i32));
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__string__init', $strPtr, [$i64, $charPtr]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_getenv_all');
        if (null === $fn) {
            throw new \LogicException('__compiler_getenv_all missing after StringGetenvAll LLVM implement');
        }
        $context->registerFunction('__compiler_getenv_all', $fn);
    }
}
