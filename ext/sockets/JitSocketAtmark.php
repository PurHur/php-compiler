<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Builtin\StringSocketAtmark;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_atmark() via SocketAtmarkJitHelper (#9215).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_atmark)
 */
final class JitSocketAtmark
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        StringSocketAtmark::ensureLinked($context);

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $handle = JitGetObjectId::invoke($context, $arg, 'socket_atmark');
        } elseif (JITVariable::TYPE_VALUE === $arg->type) {
            $handle = self::handleFromValueBox($context, $arg);
        } else {
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($arg->type));

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $atmarkI32 = JitNestedHelperCoerce::callHelper(
            $context,
            StringSocketAtmark::helperFunction($context),
            [$handle]
        );
        $atmarkI32 = $atmarkI32->typeOf() === $i32
            ? $atmarkI32
            : $context->builder->trunc($atmarkI32, $i32);
        $atmarkBool = $context->builder->icmp(Builder::INT_NE, $atmarkI32, $i32->constInt(0, false));
        JitValueBox::writeBool($context, $slot, $atmarkBool);

        return $ptr;
    }

    private static function handleFromValueBox(Context $context, JITVariable $arg): Value
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'socket_atmark_ok');
        $errBlock = BasicBlockHelper::append($context, 'socket_atmark_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage('mixed'));

        $context->builder->positionAtEnd($okBlock);
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
            'socket_atmark(): Argument #1 ($socket) must be of type Socket, %s given',
            $given
        );
    }
}
