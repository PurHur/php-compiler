<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lower string builtin operands with Z_PARAM_STR parity (#5780, ext/standard/string.c).
 */
final class JitStringBuiltinArg
{
    public static function lower(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                self::compileTimeGivenLabel($context, $arg)
            );

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $function, $argIndex, $paramName);
        }

        return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
    }

    private static function lowerBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'str_builtin_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'str_builtin_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_builtin_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'str_builtin_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            self::compileTimeGivenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($coerceBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $strictOkBlock = BasicBlockHelper::append($context, 'str_builtin_strict_ok');
            $strictErrBlock = BasicBlockHelper::append($context, 'str_builtin_strict_err');
            $context->builder->branchIf($isString, $strictOkBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed');
            $context->builder->positionAtEnd($strictOkBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function compileTimeGivenLabel(Context $context, Variable $arg): string
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            return $enumLabel;
        }
        if (Variable::KIND_VALUE !== $arg->kind || Variable::TYPE_OBJECT !== $arg->type) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            return 'object';
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($arg->value, $objMap['class_id'])
        );
        if (!method_exists($classIdVal, 'isConstant') || !$classIdVal->isConstant()) {
            return 'object';
        }
        $classId = (int) $classIdVal->getConstantValue();

        return $context->type->object->classNameForId($classId);
    }

    private static function typeErrorMessage(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $given
        );
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::typeErrorMessage($function, $argIndex, $paramName, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }
}
