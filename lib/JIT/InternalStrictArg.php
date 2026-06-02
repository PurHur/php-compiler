<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;

/**
 * Strict call-site checks for internal JIT builtins (issue #4332, zend_verify_arg_type parity).
 */
final class InternalStrictArg
{
    public static function requireInt(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceExactValueBox($context, $arg, Variable::TYPE_NATIVE_LONG, $function, $paramName, $argNumber, 'int');

            return;
        }
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($function, $argNumber, $paramName, 'int', $arg)
        );
    }

    public static function requireString(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): void {
        if (!$context->callerStrictTypes) {
            return;
        }
        if (Variable::TYPE_STRING === $arg->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceExactValueBox($context, $arg, Variable::TYPE_STRING, $function, $paramName, $argNumber, 'string');

            return;
        }
        self::raiseTypeErrorAndAbort(
            $context,
            self::message($function, $argNumber, $paramName, 'string', $arg)
        );
    }

    private static function enforceExactValueBox(
        Context $context,
        Variable $arg,
        int $expected,
        string $function,
        string $paramName,
        int $argNumber,
        string $expectedLabel
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $okBlock = BasicBlockHelper::append($context, 'internal_strict_ok');
        $failBlock = BasicBlockHelper::append($context, 'internal_strict_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt($expected, false)),
            $okBlock,
            $failBlock
        );
        $context->builder->positionAtEnd($failBlock);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                '%s(): Argument #%d ($%s) must be of type %s, %s given',
                $function,
                $argNumber,
                $paramName,
                $expectedLabel,
                'mixed'
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }

    private static function raiseTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function message(
        string $function,
        int $argNumber,
        string $paramName,
        string $expected,
        Variable $arg
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argNumber,
            $paramName,
            $expected,
            self::givenLabel($arg)
        );
    }

    private static function givenLabel(Variable $arg): string
    {
        return match ($arg->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
