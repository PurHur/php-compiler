<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\Builtin\StringSocketClose;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_close() via SocketCloseJitHelper (#27394).
 *
 * Resolve the Socket handle before NestedJIT ensureLinked — appending type-check
 * blocks after NestedJIT can orphan the user insert block under thin AOT.
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_close)
 */
final class JitSocketClose
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $handle = JitGetObjectId::invoke($context, $arg, 'socket_close');
        } elseif (JITVariable::TYPE_VALUE === $arg->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $loaded
            );
            $voidp = $context->getTypeFromString('void')->pointerType(0);
            $i64 = $context->getTypeFromString('int64');
            $handle = $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
        } else {
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($arg->type));

            return self::nullResult($context);
        }

        StringSocketClose::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_socket_close'),
            $handle
        );

        return self::nullResult($context);
    }

    private static function nullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return $ptr;
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function scalarTypeError(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return self::typeErrorMessage('int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return self::typeErrorMessage('float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return self::typeErrorMessage('bool');
            case JITVariable::TYPE_STRING:
                return self::typeErrorMessage('string');
            case JITVariable::TYPE_NULL:
                return self::typeErrorMessage('null');
            default:
                return self::typeErrorMessage('mixed');
        }
    }

    private static function typeErrorMessage(string $given): string
    {
        return \sprintf(
            'socket_close(): Argument #1 ($socket) must be of type Socket, %s given',
            $given
        );
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            'socket_close() expects exactly 1 argument, '.$argc.' given'
        );

        return self::nullResult($context);
    }
}
