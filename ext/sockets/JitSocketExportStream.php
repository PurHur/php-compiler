<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketExportStream;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_export_stream() via SocketExportStreamJitHelper (#6349).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_export_stream)
 */
final class JitSocketExportStream
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        StringSocketExportStream::ensureLinked($context);

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $lookupKey = JitGetObjectId::invoke($context, $arg, 'socket_export_stream');
        } elseif (JITVariable::TYPE_VALUE === $arg->type) {
            $lookupKey = self::lookupKeyFromValueBox($context, $arg);
        } else {
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($arg->type));

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        $i64 = $context->getTypeFromString('int64');
        $streamHandle = JitNestedHelperCoerce::callHelper(
            $context,
            StringSocketExportStream::helperFunction($context),
            [$lookupKey]
        );
        $streamHandle = $streamHandle->typeOf() === $i64
            ? $streamHandle
            : $context->builder->truncOrBitCast($streamHandle, $i64);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $zero = $i64->constInt(0, false);
        $ok = $context->builder->icmp(Builder::INT_SGT, $streamHandle, $zero);

        $failBb = BasicBlockHelper::append($context, 'socket_export_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_export_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_export_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__compiler_socket_export_warn')
        );
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        JitValueBox::writeLong($context, $slot, $streamHandle);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function lookupKeyFromValueBox(Context $context, JITVariable $arg): Value
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
        $okBlock = BasicBlockHelper::append($context, 'socket_export_ok_type');
        $errBlock = BasicBlockHelper::append($context, 'socket_export_err_type');
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

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            'socket_export_stream() expects exactly 1 argument, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
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
            'socket_export_stream(): Argument #1 ($socket) must be of type Socket, %s given',
            $given
        );
    }
}
