<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketImportStream;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitResourceArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_import_stream() via SocketImportStreamJitHelper (#9217).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_import_stream)
 */
final class JitSocketImportStream
{
    public static function invoke(Context $context, JITVariable $streamArg): Value
    {
        JitResourceArg::rejectEnumCaseOperand($context, $streamArg, 'socket_import_stream', 0, 'stream');
        StringSocketImportStream::ensureLinked($context);

        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $streamArg, 'socket_import_stream() stream'),
            $context->getTypeFromString('int64')
        );

        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $canImport = $context->builder->call(
            $context->lookupFunction('__compiler_socket_import_can_import'),
            $handle
        );
        $ok = $context->builder->icmp(Builder::INT_NE, $canImport, $zeroI32);

        $failBb = BasicBlockHelper::append($context, 'socket_import_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_import_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_import_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__compiler_socket_import_warn'),
            $handle
        );
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $objPtr = self::allocateSocketObject($context);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $i64 = $context->getTypeFromString('int64');
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($objPtr, $voidp),
            $i64
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_socket_import_register'),
            $objAddr,
            $handle
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

    private static function allocateSocketObject(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('Socket');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            'socket_import_stream() expects exactly 1 argument, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
