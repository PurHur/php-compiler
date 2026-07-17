<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin standalone AOT LLVM for hash_equals() — timing-safe byte XOR (#20065).
 *
 * Nested {@see \PHPCompiler\ext\standard\HashEqualsJitHelper} is skipped when
 * {@see \PHPCompiler\JIT\Context::isThinStandaloneAotMain()} (#20050 / Rename shape).
 * php-src: ext/hash/hash.c — hash_equals()
 */
final class JitHashEqualsKernel
{
    private const KERNEL_ENTRY = 'hash_equals_kernel_entry';

    public static function implement(Context $context): void
    {
        $abiName = '__compiler_hash_equals';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, 'hash_equals_bridge_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $strPtr, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        self::emitEqualsBody($context, $fn);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    /** Emit timing-safe compare; builder must be positioned at the bridge entry block. */
    public static function emitEqualsBody(Context $context, LlvmFunction $fn): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);

        $known = $fn->getParam(0);
        $user = $fn->getParam(1);
        $knownLen = self::stringLenI64($context, $known);
        $userLen = self::stringLenI64($context, $user);
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $knownLen, $userLen);

        $lenMismatch = $fn->appendBasicBlock('hash_equals_len_mismatch');
        $loopInit = $fn->appendBasicBlock('hash_equals_loop_init');
        $context->builder->branchIf($lenEq, $loopInit, $lenMismatch);

        $context->builder->positionAtEnd($lenMismatch);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($loopInit);
        $knownData = self::stringData($context, $known);
        $userData = self::stringData($context, $user);
        $acc = $context->builder->alloca($i32);
        $idx = $context->builder->alloca($i64);
        $context->builder->store($zeroI32, $acc);
        $context->builder->store($zeroI64, $idx);

        $loopHead = $fn->appendBasicBlock('hash_equals_loop_head');
        $loopBody = $fn->appendBasicBlock('hash_equals_loop_body');
        $loopDone = $fn->appendBasicBlock('hash_equals_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($idx);
        $cont = $context->builder->icmp(Builder::INT_ULT, $i, $knownLen);
        $context->builder->branchIf($cont, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $kPtr = $context->builder->gep($knownData, $i);
        $uPtr = $context->builder->gep($userData, $i);
        $kByte = $context->builder->zext($context->builder->load($kPtr), $i32);
        $uByte = $context->builder->zext($context->builder->load($uPtr), $i32);
        $xored = $context->builder->xor($kByte, $uByte);
        $prev = $context->builder->load($acc);
        $context->builder->store($context->builder->or($prev, $xored), $acc);
        $context->builder->store($context->builder->add($i, $oneI64), $idx);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $final = $context->builder->load($acc);
        $ok = $context->builder->icmp(Builder::INT_EQ, $final, $zeroI32);
        $context->builder->returnValue($context->builder->select($ok, $oneI32, $zeroI32));
    }

    private static function stringData(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    private static function stringLenI64(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));

        return $context->builder->truncOrBitCast($len, $context->getTypeFromString('int64'));
    }
}
