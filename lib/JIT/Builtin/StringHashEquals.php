<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM implementation of __compiler_hash_equals (issue #7189 phase 1).
 *
 * php-src: ext/hash/hash.c — timing-safe compare for hash_equals().
 * VM semantics: ext/standard/VmHash::equals().
 */
final class StringHashEquals
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_hash_equals');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_hash_equals', $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_hash_equals', $ft);
        self::implementHashEquals($context, $fn);
        $context->registerFunction('__compiler_hash_equals', $fn);
    }

    private static function implementHashEquals(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('hash_equals_entry');
        $context->builder->positionAtEnd($entry);

        $known = $fn->getParam(0);
        $user = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);

        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $accSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $knownData = self::stringData($context, $known);
        $userData = self::stringData($context, $user);

        $strlen = $context->lookupFunction('__string__strlen');
        $knownLen = $context->builder->call($strlen, $known);
        $userLen = $context->builder->call($strlen, $user);
        $lenMismatch = $context->builder->icmp(Builder::INT_NE, $knownLen, $userLen);

        $failBb = $fn->appendBasicBlock('hash_equals_fail');
        $loopInit = $fn->appendBasicBlock('hash_equals_loop_init');
        $loopHead = $fn->appendBasicBlock('hash_equals_loop_head');
        $context->builder->branchIf($lenMismatch, $failBb, $loopInit);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($loopInit);
        $context->builder->store($zeroI64, $iSlot);
        $context->builder->store($zeroI32, $accSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $knownLen);
        $loopBody = $fn->appendBasicBlock('hash_equals_loop_body');
        $loopDone = $fn->appendBasicBlock('hash_equals_loop_done');
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ka = $context->builder->load($context->builder->gep($knownData, $i));
        $ua = $context->builder->load($context->builder->gep($userData, $i));
        $xor = $context->builder->xor(
            $context->builder->zExt($ka, $i32),
            $context->builder->zExt($ua, $i32)
        );
        $acc = $context->builder->load($accSlot);
        $context->builder->store($context->builder->or($acc, $xor), $accSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $oneI64), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $finalAcc = $context->builder->load($accSlot);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $finalAcc, $zeroI32);
        $okBb = $fn->appendBasicBlock('hash_equals_ok');
        $accFailBb = $fn->appendBasicBlock('hash_equals_acc_fail');
        $context->builder->branchIf($isZero, $okBb, $accFailBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($accFailBb);
        $context->builder->returnValue($zeroI32);
        $context->builder->clearInsertionPosition();
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }
}
