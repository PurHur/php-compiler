<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\LdapRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for ldap_compare() / ldap_count_entries() (#32121, #32172). */
final class JitLdapResult
{
    /** @param list<JITVariable> $args */
    public static function invokeCompare(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 4 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_compare() expects between 4 and 5 arguments, %d given',
                $argc
            ));
        }

        $handle = self::lowerConnectionHandle($context, $args[0], 'ldap_compare');
        $dn = JitStringBuiltinArg::lower($context, $args[1], 'ldap_compare', 1, 'dn');
        $attribute = JitStringBuiltinArg::lower($context, $args[2], 'ldap_compare', 2, 'attribute');
        $value = JitStringBuiltinArg::lower($context, $args[3], 'ldap_compare', 3, 'value');

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_ldap_compare'),
            $handle,
            $dn,
            $attribute,
            $value
        );
    }

    /** @param list<JITVariable> $args */
    public static function invokeCountEntries(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_count_entries() expects exactly 2 arguments, %d given',
                $argc
            ));
        }

        $conn = self::lowerConnectionHandle($context, $args[0], 'ldap_count_entries');
        $result = self::lowerResultHandle($context, $args[1], 'ldap_count_entries');

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        LdapRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $count = $context->builder->call(
            $context->lookupFunction('__compiler_ldap_count_entries'),
            $conn,
            $result
        );

        return self::longFromI64($context, $count);
    }

    /**
     * Map a NestedJIT LDAP\Result return value to its VM result id (#32172).
     *
     * Peer: ldap_connect() register in {@see ldap_connect::call}.
     */
    public static function registerReturnedResult(Context $context, Value $result): Value
    {
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($result, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $regBb = BasicBlockHelper::append($context, 'ldap_result_register');
        $doneBb = BasicBlockHelper::append($context, 'ldap_result_done');
        $context->builder->branchIf($isObject, $regBb, $doneBb);

        $context->builder->positionAtEnd($regBb);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $result
        );
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $i64 = $context->getTypeFromString('int64');
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($obj, $voidp),
            $i64
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_ldap_result_register'),
            $objAddr
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);

        return $result;
    }

    private static function longFromI64(Context $context, Value $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $value);

        return $ptr;
    }

    private static function lowerResultHandle(Context $context, JITVariable $arg, string $function): Value
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

        self::emitTypeErrorAndAbort($context, self::resultScalarTypeError($function, $arg->type));

        return $context->getTypeFromString('int64')->constInt(0, false);
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

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function scalarTypeError(string $function, int $type): string
    {
        return self::typeErrorMessage($function, 1, 'ldap', 'LDAP\\Connection', self::typeLabel($type));
    }

    private static function resultScalarTypeError(string $function, int $type): string
    {
        return self::typeErrorMessage($function, 2, 'result', 'LDAP\\Result', self::typeLabel($type));
    }

    private static function typeLabel(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_NULL:
                return 'null';
            default:
                return 'mixed';
        }
    }

    private static function typeErrorMessage(
        string $function,
        int $argNum,
        string $param,
        string $expected,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argNum,
            $param,
            $expected,
            $given
        );
    }
}
