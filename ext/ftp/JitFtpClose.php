<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFtpClose;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for ftp_close() / ftp_quit() via FtpCloseJitHelper (#31377).
 *
 * Resolve the FTP\Connection handle before NestedJIT ensureLinked — appending
 * type-check blocks after NestedJIT can orphan the user insert block under thin AOT
 * (peer JitSocketClose / #27394).
 *
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_close) / PHP_FALIAS(ftp_quit)
 */
final class JitFtpClose
{
    public static function invoke(Context $context, string $function, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $handle = JitGetObjectId::invoke($context, $arg, $function);
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
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($function, $arg->type));

            return self::boolResult($context, false);
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpClose::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_close'),
            $handle
        );

        return self::boolFromI1($context, $ok);
    }

    private static function boolFromI1(Context $context, Value $ok): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }

    private static function boolResult(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false)
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

    private static function scalarTypeError(string $function, int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return self::typeErrorMessage($function, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return self::typeErrorMessage($function, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return self::typeErrorMessage($function, 'bool');
            case JITVariable::TYPE_STRING:
                return self::typeErrorMessage($function, 'string');
            case JITVariable::TYPE_NULL:
                return self::typeErrorMessage($function, 'null');
            default:
                return self::typeErrorMessage($function, 'mixed');
        }
    }

    private static function typeErrorMessage(string $function, string $given): string
    {
        return \sprintf(
            '%s(): Argument #1 ($ftp) must be of type FTP\\Connection, %s given',
            $function,
            $given
        );
    }

    public static function emitArgumentCountError(Context $context, string $function, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            $function.'() expects exactly 1 argument, '.$argc.' given'
        );

        return self::boolResult($context, false);
    }
}
