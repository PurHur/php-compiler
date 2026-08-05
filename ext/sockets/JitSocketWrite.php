<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketPairIo;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_write() via SocketPairIoJitHelper (#27423).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_write)
 */
final class JitSocketWrite
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_write() expects at least 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $data = JitStringArg::lower($context, $args[1], 'socket_write() data');
        $i64 = $context->getTypeFromString('int64');
        if ($argc >= 3) {
            $length = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'socket_write() length'),
                $i64
            );
        } else {
            $length = $context->builder->call(
                $context->lookupFunction('__string__strlen'),
                $data
            );
        }

        StringSocketPairIo::ensureLinked($context);
        $n = $context->builder->call(
            $context->lookupFunction('__compiler_socket_write'),
            $handle,
            $data,
            $length
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $neg = $context->builder->icmp(Builder::INT_SLT, $n, $i64->constInt(0, true));
        $failBb = BasicBlockHelper::append($context, 'socket_write_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_write_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_write_done');
        $context->builder->branchIf($neg, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        JitValueBox::writeLong($context, $slot, $n);
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
            return JitGetObjectId::invoke($context, $arg, 'socket_write');
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
            'socket_write(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
