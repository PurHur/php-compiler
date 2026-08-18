<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LdapRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for ldap_bind() / ldap_unbind() / ldap_close() / ldap_set/get_option() (#32001, #32002, #32107). */
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

    /** @param list<JITVariable> $args */
    public static function invokeSetOption(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_set_option() expects exactly 3 arguments, %d given',
                $argc
            ));
        }

        [$handle, $hasConn] = self::lowerNullableConnectionHandle($context, $args[0], 'ldap_set_option');
        $option = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'ldap_set_option', 2, 'option');
        [$value, $valueKind] = self::lowerSetOptionValue($context, $args[2]);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_set_option'),
            $handle,
            $hasConn,
            $option,
            $value,
            $valueKind
        );

        return self::boolFromI1($context, $ok);
    }

    /** @param list<JITVariable> $args */
    public static function invokeGetOption(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_get_option() expects exactly 3 arguments, %d given',
                $argc
            ));
        }

        [$handle, $hasConn] = self::lowerNullableConnectionHandle($context, $args[0], 'ldap_get_option');
        $option = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'ldap_get_option', 2, 'option');

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $okI64 = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_get_option'),
            $handle,
            $hasConn,
            $option
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $truthy = $context->builder->icmp(
            Builder::INT_SGT,
            $okI64,
            $i64->constInt(0, false)
        );
        $failBb = BasicBlockHelper::append($context, 'ldap_get_option_fail');
        $okBb = BasicBlockHelper::append($context, 'ldap_get_option_ok');
        $doneBb = BasicBlockHelper::append($context, 'ldap_get_option_done');
        $context->builder->branchIf($truthy, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $val = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_get_option_value')
        );
        $outPtr = JitValueBox::valuePtrFromVariable($context, $args[2]);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $val
        );
        JitValueBox::writeBool($context, $slot, $i1->constInt(1, false));
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    /**
     * @return array{0: Value, 1: Value} handle, hasConn (1 = LDAP\\Connection, 0 = null)
     */
    private static function lowerNullableConnectionHandle(Context $context, JITVariable $arg, string $function): array
    {
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NULL === $arg->type) {
            return [$i64->constInt(0, false), $i64->constInt(0, false)];
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerValueBoxNullableConnection($context, $arg, $function);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return [self::lowerConnectionHandle($context, $arg, $function), $i64->constInt(1, false)];
        }

        self::emitTypeErrorAndAbort($context, self::scalarTypeError($function, $arg->type));

        return [$i64->constInt(0, false), $i64->constInt(0, false)];
    }

    /**
     * @return array{0: Value, 1: Value} handle, hasConn
     */
    private static function lowerValueBoxNullableConnection(Context $context, JITVariable $arg, string $function): array
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $doneBb = BasicBlockHelper::append($context, 'ldap_opt_conn_done');

        $nullBb = BasicBlockHelper::append($context, 'ldap_opt_conn_null');
        $afterNullBb = BasicBlockHelper::append($context, 'ldap_opt_conn_after_null');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBb, $afterNullBb);

        $context->builder->positionAtEnd($nullBb);
        $nullHandle = $i64->constInt(0, false);
        $nullHas = $i64->constInt(0, false);
        $nullTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterNullBb);
        $objBb = BasicBlockHelper::append($context, 'ldap_opt_conn_obj');
        $badBb = BasicBlockHelper::append($context, 'ldap_opt_conn_bad');
        $isObj = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObj, $objBb, $badBb);

        $context->builder->positionAtEnd($badBb);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'mixed'));
        $badHandle = $i64->constInt(0, false);
        $badHas = $i64->constInt(0, false);
        $badTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($objBb);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $objHandle = $context->builder->ptrToInt(
            $context->builder->pointerCast($obj, $voidp),
            $i64
        );
        $objHas = $i64->constInt(1, false);
        $objTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $handlePhi = $context->builder->phi($i64);
        $handlePhi->addIncoming($nullHandle, $nullTail);
        $handlePhi->addIncoming($badHandle, $badTail);
        $handlePhi->addIncoming($objHandle, $objTail);
        $hasPhi = $context->builder->phi($i64);
        $hasPhi->addIncoming($nullHas, $nullTail);
        $hasPhi->addIncoming($badHas, $badTail);
        $hasPhi->addIncoming($objHas, $objTail);

        return [$handlePhi, $hasPhi];
    }

    /**
     * @return array{0: Value, 1: Value} value i64, valueKind (1 = int/bool, 0 = unsupported)
     */
    private static function lowerSetOptionValue(Context $context, JITVariable $arg): array
    {
        $i64 = $context->getTypeFromString('int64');
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_BOOL:
            case JITVariable::TYPE_VALUE:
                return [
                    JitLongArg::lower($context, $arg, 'ldap_set_option() newval'),
                    $i64->constInt(1, false),
                ];
            default:
                return [$i64->constInt(0, false), $i64->constInt(0, false)];
        }
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
