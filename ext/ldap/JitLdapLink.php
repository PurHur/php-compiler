<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LdapRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** LLVM lowering for ldap_bind() / ldap_unbind() / ldap_close() (#32001, #32002). */
final class JitLdapLink
{
    /** @param list<JITVariable> $args */
    public static function invokeBind(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_bind() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }

        $handle = self::lowerConnectionHandle($context, $args[0], 'ldap_bind');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        if ($argc >= 2) {
            $hasDn = $i64->constInt(1, false);
            $dn = JitStringBuiltinArg::lowerNullableString(
                $context,
                $args[1],
                'ldap_bind',
                1,
                'dn'
            );
        } else {
            $hasDn = $i64->constInt(0, false);
            $dn = $strPtr->constNull();
        }
        if ($argc >= 3) {
            $hasPassword = $i64->constInt(1, false);
            $password = JitStringBuiltinArg::lowerNullableString(
                $context,
                $args[2],
                'ldap_bind',
                2,
                'password'
            );
        } else {
            $hasPassword = $i64->constInt(0, false);
            $password = $strPtr->constNull();
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_bind'),
            $handle,
            $dn,
            $password,
            $hasDn,
            $hasPassword
        );

        return self::boolFromI1($context, $ok);
    }

    /** @param list<JITVariable> $args */
    public static function invokeUnbind(Context $context, array $args, string $function): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 1 argument, %d given',
                $function,
                $argc
            ));
        }

        $handle = self::lowerConnectionHandle($context, $args[0], $function);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_unbind'),
            $handle
        );

        return self::boolFromI1($context, $ok);
    }

    /** @param list<JITVariable> $args */
    public static function invokeErrno(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_errno() expects exactly 1 argument, %d given',
                $argc
            ));
        }

        $handle = self::lowerConnectionHandle($context, $args[0], 'ldap_errno');
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $errno = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_errno'),
            $handle
        );

        return self::longFromI64($context, $errno);
    }

    /** @param list<JITVariable> $args */
    public static function invokeError(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_error() expects exactly 1 argument, %d given',
                $argc
            ));
        }

        $handle = self::lowerConnectionHandle($context, $args[0], 'ldap_error');
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $error = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_error'),
            $handle
        );

        return self::stringFromPtr($context, $error);
    }

    /** @param list<JITVariable> $args */
    public static function invokeErr2str(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_err2str() expects exactly 1 argument, %d given',
                $argc
            ));
        }

        $errno = JitIntdiv::lowerIntBuiltinArg($context, $args[0], 'ldap_err2str', 1, 'errno');
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $error = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_err2str'),
            $errno
        );

        return self::stringFromPtr($context, $error);
    }

    private static function lowerConnectionHandle(Context $context, JITVariable $arg, string $function): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, $function);
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

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($function, $arg->type));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function boolFromI1(Context $context, Value $ok): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }

    private static function longFromI64(Context $context, Value $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $value);

        return $ptr;
    }

    private static function stringFromPtr(Context $context, Value $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $value
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
            '%s(): Argument #1 ($ldap) must be of type LDAP\\Connection, %s given',
            $function,
            $given
        );
    }
}
