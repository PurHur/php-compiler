<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFtpLogin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for ftp_login() via FtpLoginJitHelper (#31378).
 *
 * Resolve/coerce args before NestedJIT ensureLinked — peer JitFtpClose / #31377.
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_login)
 */
final class JitFtpLogin
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            return self::emitArgumentCountError($context, $argc);
        }

        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            $handle = JitGetObjectId::invoke($context, $args[0], 'ftp_login');
        } elseif (JITVariable::TYPE_VALUE === $args[0]->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $args[0]);
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
            self::emitTypeErrorAndAbort($context, self::scalarTypeError($args[0]->type));

            return self::boolResult($context, false);
        }

        $username = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_login',
            1,
            'username'
        );
        $password = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[2],
            'ftp_login',
            2,
            'password'
        );

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpLogin::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_login'),
            $handle,
            $username,
            $password
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
            'ftp_login(): Argument #1 ($ftp) must be of type FTP\\Connection, %s given',
            $given
        );
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            'ftp_login() expects exactly 3 arguments, '.$argc.' given'
        );

        return self::boolResult($context, false);
    }
}
