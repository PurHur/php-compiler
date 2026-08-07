<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringShmop;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for shmop_open() via ShmopJitHelper (#27408).
 *
 * php-src: ext/shmop/shmop.c — PHP_FUNCTION(shmop_open)
 */
final class JitShmopOpen
{
    public static function invoke(
        Context $context,
        JITVariable $keyArg,
        JITVariable $modeArg,
        JITVariable $permissionsArg,
        JITVariable $sizeArg
    ): Value {
        StringShmop::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $key = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $keyArg, 'shmop_open() key'),
            $i64
        );
        $modeStr = JitStringBuiltinArg::lowerTypedString($context, $modeArg, 'shmop_open', 2, 'mode');
        $modeChar = self::modeFirstCharOrThrow($context, $modeStr);
        $permissions = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $permissionsArg, 'shmop_open() permissions'),
            $i64
        );
        $size = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $sizeArg, 'shmop_open() size'),
            $i64
        );

        $shmid = $context->builder->call(
            $context->lookupFunction('__compiler_shmop_open'),
            $key,
            $modeChar,
            $permissions,
            $size
        );
        $zero = $i64->constInt(0, true);
        $ok = $context->builder->icmp(Builder::INT_SGE, $shmid, $zero);

        $failBb = BasicBlockHelper::append($context, 'shmop_open_fail');
        $okBb = BasicBlockHelper::append($context, 'shmop_open_ok');
        $doneBb = BasicBlockHelper::append($context, 'shmop_open_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $objPtr = self::allocateShmopObject($context);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($objPtr, $voidp),
            $i64
        );
        $addr = $context->builder->call($context->lookupFunction('__compiler_shmop_pending_addr'));
        $segSize = $context->builder->call($context->lookupFunction('__compiler_shmop_pending_size'));
        $readonly = $context->builder->call($context->lookupFunction('__compiler_shmop_pending_readonly'));
        $context->builder->call(
            $context->lookupFunction('__compiler_shmop_register'),
            $objAddr,
            $shmid,
            $addr,
            $segSize,
            $readonly
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $objPtr
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function modeFirstCharOrThrow(Context $context, Value $modeStr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $stringMap = $context->structFieldMap['__string__'];
        $len = $context->builder->zext(
            $context->builder->load($context->builder->structGep($modeStr, $stringMap['length'])),
            $i64
        );
        $one = $i64->constInt(1, false);
        $lenOk = $context->builder->icmp(Builder::INT_EQ, $len, $one);

        $okBb = BasicBlockHelper::append($context, 'shmop_mode_len_ok');
        $errBb = BasicBlockHelper::append($context, 'shmop_mode_len_err');
        $context->builder->branchIf($lenOk, $okBb, $errBb);

        $context->builder->positionAtEnd($errBb);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'shmop_open(): Argument #2 ($mode) must be a valid access mode'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($okBb);
        $data = $context->builder->structGep($modeStr, $stringMap['value']);
        $byte = $context->builder->load($data);
        $char = $context->builder->zext($byte, $i64);

        $isA = $context->builder->icmp(Builder::INT_EQ, $char, $i64->constInt(\ord('a'), false));
        $isC = $context->builder->icmp(Builder::INT_EQ, $char, $i64->constInt(\ord('c'), false));
        $isN = $context->builder->icmp(Builder::INT_EQ, $char, $i64->constInt(\ord('n'), false));
        $isW = $context->builder->icmp(Builder::INT_EQ, $char, $i64->constInt(\ord('w'), false));
        $modeOk = $context->builder->or(
            $isA,
            $context->builder->or($isC, $context->builder->or($isN, $isW))
        );

        $modeOkBb = BasicBlockHelper::append($context, 'shmop_mode_char_ok');
        $modeErrBb = BasicBlockHelper::append($context, 'shmop_mode_char_err');
        $context->builder->branchIf($modeOk, $modeOkBb, $modeErrBb);

        $context->builder->positionAtEnd($modeErrBb);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'shmop_open(): Argument #2 ($mode) must be a valid access mode'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($modeOkBb);

        return $char;
    }

    private static function allocateShmopObject(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('Shmop');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            'shmop_open() expects exactly 4 arguments, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
