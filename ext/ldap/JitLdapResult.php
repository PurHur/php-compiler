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
use PHPLLVM\Value;

/** LLVM lowering for ldap_compare() (#32121). */
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
