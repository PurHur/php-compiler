<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Init-safe libc environ walk into native __hashtable__* (#19157, #19555).
 *
 * Moved out of lib/JIT/Builtin/ — shared by {@see phpc_native_environ_mirror_into_ht}
 * and user-script superglobal refresh via {@see EnvironMirrorNativeJitHelper}.
 * php-src: sapi/cli/php_cli.c — copy environ into $_SERVER on CLI startup
 */
final class JitEnvironMirrorKernel
{
    public static function ensureEnvironGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal('environ')) {
            return;
        }
        $i8pp = $context->getTypeFromString('int8*')->pointerType(0);
        $context->module->addGlobal($i8pp, 'environ');
    }

    public static function mirrorIntoHashtable(Context $context, JITVariable $destPtr): void
    {
        LibcExtern::register($context);
        self::ensureEnvironGlobal($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $i64 = $context->getTypeFromString('int64');

        $ht = JitNestedHelperCoerce::i64ToTypedPtr(
            $context,
            self::i64FromVar($context, $destPtr),
            $htPtr
        );
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $fn = $context->functions[$context->activeFunction] ?? null;
        if (!$fn instanceof \PHPLLVM\Value\Function_) {
            throw new \LogicException('JitEnvironMirrorKernel requires active function (#19157)');
        }

        $doneBb = $fn->appendBasicBlock('environ_libc_done');
        $bodyBb = $fn->appendBasicBlock('environ_libc_body');
        $context->builder->branchIf($htNull, $doneBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $idxSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $environGlobal = $context->module->getNamedGlobal('environ');
        if (null === $environGlobal) {
            throw new \LogicException('environ global missing for libc mirror (#19157)');
        }
        $environPtr = $context->builder->load($environGlobal);
        $environNull = $context->builder->icmp(Builder::INT_EQ, $environPtr, $i8pp->constNull());
        $loopHeadBb = $fn->appendBasicBlock('environ_libc_loop_head');
        $context->builder->branchIf($environNull, $doneBb, $loopHeadBb);

        $context->builder->positionAtEnd($loopHeadBb);
        $idx = $context->builder->load($idxSlot);
        $entryPtr = $context->builder->gep($environPtr, $idx);
        $pairPtr = $context->builder->load($entryPtr);
        $pairNull = $context->builder->icmp(Builder::INT_EQ, $pairPtr, $i8p->constNull());
        $loopBodyBb = $fn->appendBasicBlock('environ_libc_loop_body');
        $context->builder->branchIf($pairNull, $doneBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $eqPtr = $context->builder->call(
            $context->lookupFunction('strchr'),
            $pairPtr,
            $i32->constInt(ord('='), false)
        );
        $eqNull = $context->builder->icmp(Builder::INT_EQ, $eqPtr, $i8p->constNull());
        $skipBb = $fn->appendBasicBlock('environ_libc_skip_pair');
        $setBb = $fn->appendBasicBlock('environ_libc_set_pair');
        $incBb = $fn->appendBasicBlock('environ_libc_inc');
        $context->builder->branchIf($eqNull, $skipBb, $setBb);

        $context->builder->positionAtEnd($setBb);
        $keyLen = $context->builder->ptrDiff($eqPtr, $pairPtr);
        $keyLenI64 = $context->builder->sext($keyLen, $i64);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $keyLenI64,
            $pairPtr
        );
        $valStart = $context->builder->gep($eqPtr, $i8->constInt(1, false));
        $valLen = $context->builder->call($context->lookupFunction('strlen'), $valStart);
        $valLenI64 = $context->builder->zExt($valLen, $i64);
        $valStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $valLenI64,
            $valStart
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
        $context->builder->branch($incBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($incBb);

        $context->builder->positionAtEnd($incBb);
        $nextIdx = $context->builder->add($idx, $i64->constInt(1, false));
        $context->builder->store($nextIdx, $idxSlot);
        $context->builder->branch($loopHeadBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function i64FromVar(Context $context, JITVariable $var): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG === $var->type) {
            $raw = $var->value;
            $ty = $context->getStringFromType($raw->typeOf());
            if ('int64' === $ty || 'long long' === $ty) {
                return $raw;
            }

            return $context->builder->load($raw);
        }

        throw new \LogicException('JitEnvironMirrorKernel: expected native long dest pointer (#19157)');
    }
}
