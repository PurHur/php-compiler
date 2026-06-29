<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_getenv — libc getenv into a __value__ out-parameter.
 *
 * VM/JIT/AOT/standalone share this path; superglobals_refresh.c no longer duplicates it (#5330).
 */
final class StringGetenvLibcBridge
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
        $probe = $context->module->getNamedFunction('__compiler_getenv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_getenv', $probe);

            return;
        }

        $fn = $context->lookupFunction('__compiler_getenv');

        EnvLocalRuntime::ensureLinked($context);

        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $localOnly = $fn->getParam(1);
        $out = $fn->getParam(2);
        $strMap = $context->structFieldMap['__string__'];
        $valMap = $context->structFieldMap['__value__'];
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);

        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $nameBytes = $context->builder->structGep($name, $strMap['value']);
        $bufLen = $context->builder->add($nameLen, $i64->constInt(1, false));
        $nameBuf = $context->builder->alloca($i8, $bufLen, 'getenv_name');
        $nameCStr = $context->builder->pointerCast($nameBuf, $i8p);
        $context->intrinsic->memcpy($nameCStr, $nameBytes, $nameLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($nameCStr, $nameLen)
        );

        $routeLocal = $fn->appendBasicBlock('getenv_route_local');
        $useLibcOnly = $fn->appendBasicBlock('getenv_use_libc_only');
        $tryLocal = $fn->appendBasicBlock('getenv_try_local');
        $localMiss = $fn->appendBasicBlock('getenv_local_miss');
        $localHit = $fn->appendBasicBlock('getenv_local_hit');
        $mergeEnv = $fn->appendBasicBlock('getenv_merge_env');
        $isLocal = $context->builder->icmp(Builder::INT_NE, $localOnly, $i8->constInt(0, false));
        $context->builder->branchIf($isLocal, $routeLocal, $useLibcOnly);

        $context->builder->positionAtEnd($routeLocal);
        $context->builder->branch($tryLocal);

        $context->builder->positionAtEnd($tryLocal);
        $envLocal = $context->builder->call(
            $context->lookupFunction('__compiler_env_local_lookup'),
            $nameCStr
        );
        $localNull = $context->builder->icmp(Builder::INT_EQ, $envLocal, $i8p->constNull());
        $context->builder->branchIf($localNull, $localMiss, $localHit);

        $context->builder->positionAtEnd($localMiss);
        $envAfterMiss = $context->builder->call($context->lookupFunction('getenv'), $nameCStr);
        $context->builder->branch($mergeEnv);

        $context->builder->positionAtEnd($localHit);
        $context->builder->branch($mergeEnv);

        $context->builder->positionAtEnd($useLibcOnly);
        $envLibcOnly = $context->builder->call($context->lookupFunction('getenv'), $nameCStr);
        $context->builder->branch($mergeEnv);

        $context->builder->positionAtEnd($mergeEnv);
        $phi = $context->builder->phi($i8p, 'getenv_result');
        $phi->addIncoming($envAfterMiss, $localMiss);
        $phi->addIncoming($envLocal, $localHit);
        $phi->addIncoming($envLibcOnly, $useLibcOnly);
        $env = $phi;

        $isNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());

        $missing = $fn->appendBasicBlock('getenv_missing');
        $found = $fn->appendBasicBlock('getenv_found');
        $done = $fn->appendBasicBlock('getenv_done');
        $context->builder->branchIf($isNull, $missing, $found);

        $context->builder->positionAtEnd($missing);
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($out, $valMap['type'])
        );
        $valueField = $context->builder->structGep($out, $valMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $zero
        );
        $context->builder->store($i8->constInt(0, false), $firstByte);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($found);
        $len = $context->builder->call($context->lookupFunction('strlen'), $env);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $env
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $str
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->registerFunction('__compiler_getenv', $fn);
    }
}
