<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/**
 * MCJIT embed ob_* bodies — LLVM IR + write(2), no C bitcode/dlsym (#98, #2055).
 */
final class EmbedObOutput
{
    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        self::ensureWriteDecl($context);
        self::implementNoop($context, '__phpc_ob_start');
        self::implementReturnZero($context, '__phpc_ob_get_level');
        self::implementReturnZero($context, '__phpc_ob_get_clean');
        self::implementReturnZero($context, '__phpc_ob_end_flush');
        self::implementEchoCstr($context);
        self::implementEchoChar($context);
        self::implementEchoLl($context);
        self::implementEchoDouble($context);
        self::implementEchoSubstr($context);
    }

    private static function ensureWriteDecl(Context $context): void
    {
        if (null !== $context->module->getNamedFunction('write')) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i64, false, $i32, $i8p, $i64);
        $context->module->addFunction('write', $ft);
    }

    private static function writeFn(Context $context): \PHPLLVM\Value\Function_
    {
        $fn = $context->module->getNamedFunction('write');
        if (null === $fn) {
            throw new \LogicException('write declaration missing for embed ob output');
        }

        return $fn;
    }

    private static function implementNoop(Context $context, string $name): void
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementReturnZero(Context $context, string $name): void
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->clearInsertionPosition();
    }

    private static function emitWrite(Context $context, \PHPLLVM\Value $buf, \PHPLLVM\Value $len): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $context->builder->call(
            self::writeFn($context),
            $i32->constInt(1, false),
            $context->builder->pointerCast($buf, $i8p),
            $context->builder->zExt($len, $i64)
        );
    }

    private static function implementEchoCstr(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_ob_echo_cstr');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('entry');
        $loop = $fn->appendBasicBlock('len_loop');
        $inc = $fn->appendBasicBlock('len_loop_inc');
        $done = $fn->appendBasicBlock('len_done');
        $null = $fn->appendBasicBlock('null');
        $context->builder->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $lenSlot = $context->builder->alloca($sizeT, 1, 'len');
        $context->builder->store($sizeT->constInt(0, false), $lenSlot);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'idx');
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $s, $i8p->constNull());
        $context->builder->branchIf($isNull, $null, $loop);
        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxSlot);
        $chPtr = $context->builder->inBoundsGEP($s, $idx);
        $ch = $context->builder->load($chPtr);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $context->builder->branchIf($isZero, $done, $inc);
        $context->builder->positionAtEnd($inc);
        $context->builder->store(
            $context->builder->addNoUnsignedWrap($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->store(
            $context->builder->addNoUnsignedWrap($context->builder->load($lenSlot), $sizeT->constInt(1, false)),
            $lenSlot
        );
        $context->builder->branch($loop);
        $context->builder->positionAtEnd($done);
        self::emitWrite($context, $s, $context->builder->load($lenSlot));
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($null);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementEchoChar(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_ob_echo_char');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $slot = $context->builder->alloca($i8, 1, 'c');
        $context->builder->store($fn->getParam(0), $slot);
        self::emitWrite($context, $slot, $sizeT->constInt(1, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementEchoSubstr(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_ob_echo_substr');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        self::emitWrite($context, $fn->getParam(0), $fn->getParam(1));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementEchoDouble(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_ob_echo_double');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $buf = $context->builder->alloca($i8->arrayType(64), 1, 'dbl');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $digits = $context->constantFromString('0');
        self::emitWrite($context, $context->builder->pointerCast($digits, $i8p), $sizeT->constInt(1, false));
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementEchoLl(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_ob_echo_ll');
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $zeroIdx = $sizeT->constInt(0, false);

        $entry = $fn->appendBasicBlock('entry');
        $zeroBb = $fn->appendBasicBlock('zero');
        $signBb = $fn->appendBasicBlock('sign');
        $negAbs = $fn->appendBasicBlock('neg_abs');
        $posPath = $fn->appendBasicBlock('pos_path');
        $loop = $fn->appendBasicBlock('loop');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $loopDone = $fn->appendBasicBlock('loop_done');
        $negEmit = $fn->appendBasicBlock('neg_emit');
        $emit = $fn->appendBasicBlock('emit');

        $context->builder->positionAtEnd($entry);
        $v = $fn->getParam(0);
        $buf = $context->builder->alloca($i8->arrayType(32), 1, 'num');
        $bufBase = $context->builder->inBoundsGEP($buf, $zeroIdx, $zeroIdx);
        $posSlot = $context->builder->alloca($sizeT, 1, 'pos');
        $valSlot = $context->builder->alloca($i64, 1, 'val');
        $negSlot = $context->builder->alloca($i1, 1, 'neg');
        $startSlot = $context->builder->alloca($sizeT, 1, 'start');
        $lenSlot = $context->builder->alloca($sizeT, 1, 'len');
        $context->builder->store($sizeT->constInt(31, false), $posSlot);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $v, $i64->constInt(0, false));
        $context->builder->branchIf($isZero, $zeroBb, $signBb);

        $context->builder->positionAtEnd($zeroBb);
        $context->builder->store($i8->constInt(48, false), $bufBase);
        self::emitWrite($context, $bufBase, $sizeT->constInt(1, false));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($signBb);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $v, $i64->constInt(0, false));
        $context->builder->branchIf($isNeg, $negAbs, $posPath);

        $context->builder->positionAtEnd($negAbs);
        $context->builder->store($i1->constInt(1, false), $negSlot);
        $context->builder->store($context->builder->sub($i64->constInt(0, false), $v), $valSlot);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($posPath);
        $context->builder->store($i1->constInt(0, false), $negSlot);
        $context->builder->store($v, $valSlot);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $cur = $context->builder->load($valSlot);
        $isDone = $context->builder->icmp(Builder::INT_EQ, $cur, $i64->constInt(0, false));
        $context->builder->branchIf($isDone, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ten = $i64->constInt(10, false);
        $digit = $context->builder->unsigendRem($cur, $ten);
        $context->builder->store($context->builder->unsignedDiv($cur, $ten), $valSlot);
        $posVal = $context->builder->load($posSlot);
        $ch = $context->builder->trunc($context->builder->add($digit, $i64->constInt(48, false)), $i8);
        $context->builder->store($ch, $context->builder->inBoundsGEP($bufBase, $posVal));
        $context->builder->store(
            $context->builder->subNoUnsignedWrap($posVal, $sizeT->constInt(1, false)),
            $posSlot
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loopDone);
        $start = $context->builder->addNoUnsignedWrap($context->builder->load($posSlot), $sizeT->constInt(1, false));
        $len = $context->builder->subNoUnsignedWrap($sizeT->constInt(31, false), $context->builder->load($posSlot));
        $context->builder->store($start, $startSlot);
        $context->builder->store($len, $lenSlot);
        $wasNeg = $context->builder->load($negSlot);
        $context->builder->branchIf($wasNeg, $negEmit, $emit);

        $context->builder->positionAtEnd($negEmit);
        $startNeg = $context->builder->subNoUnsignedWrap($context->builder->load($startSlot), $sizeT->constInt(1, false));
        $context->builder->store($i8->constInt(45, false), $context->builder->inBoundsGEP($bufBase, $startNeg));
        $context->builder->store($startNeg, $startSlot);
        $context->builder->store(
            $context->builder->addNoUnsignedWrap($context->builder->load($lenSlot), $sizeT->constInt(1, false)),
            $lenSlot
        );
        $context->builder->branch($emit);

        $context->builder->positionAtEnd($emit);
        self::emitWrite(
            $context,
            $context->builder->inBoundsGEP($bufBase, $context->builder->load($startSlot)),
            $context->builder->load($lenSlot)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }
}
