<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketPairIo;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_read() via SocketPairIoJitHelper (#27423).
 *
 * Binary read path (default PHP_BINARY_READ) for the AOT round-trip repro.
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_read)
 */
final class JitSocketRead
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_read() expects at least 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $length = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'socket_read() length'),
            $i64
        );

        StringSocketPairIo::ensureLinked($context);
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_socket_read'),
            $handle,
            $length
        );
        $failedI32 = $context->builder->call(
            $context->lookupFunction('__compiler_socket_read_failed')
        );
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_NE, $failedI32, $i32->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBb = BasicBlockHelper::append($context, 'socket_read_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_read_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_read_done');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_read');
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $loaded
            );
            $voidp = $context->getTypeFromString('void')->pointerType(0);
            $i64 = $context->getTypeFromString('int64');

            return $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
        }
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            'socket_read(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
