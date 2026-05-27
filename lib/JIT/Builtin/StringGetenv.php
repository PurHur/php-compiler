<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_getenv — libc getenv into a __value__ out-parameter.
 *
 * Standalone AOT uses the C runtime in lib/AOT/runtime/superglobals_refresh.c (issue #1068).
 */
final class StringGetenv
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_getenv');
        // Standalone AOT links a C runtime implementation of __compiler_getenv; only declare it here.
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }

        $entry = $fn->appendBasicBlock('main');
        $context->builder->positionAtEnd($entry);

        $name = $fn->getParam(0);
        $out = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        $valMap = $context->structFieldMap['__value__'];
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $strMap['length'])
        );
        $nameBytes = $context->builder->structGep($name, $strMap['value']);
        $bufLen = $context->builder->add($nameLen, $one);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $nameBuf = $context->builder->call(
                $context->lookupFunction('__mm__malloc'),
                $bufLen
            );
            $nameCStr = $context->builder->pointerCast($nameBuf, $i8p);
        } else {
            $nameBuf = $context->builder->alloca($i8, $bufLen, 'getenv_name');
            $nameCStr = $context->builder->pointerCast($nameBuf, $i8p);
        }
        $context->intrinsic->memcpy($nameCStr, $nameBytes, $nameLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($nameCStr, $nameLen)
        );
        $env = $context->builder->call($context->lookupFunction('getenv'), $nameCStr);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('__mm__free'), $nameBuf);
        }
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
    }
}
