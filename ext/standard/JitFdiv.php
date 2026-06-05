<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fdiv() operand coercion (php-src math.c; #6185 enum-case TypeError). */
final class JitFdiv
{
    private const FUNCTION = 'fdiv';

    public static function lowerOperands(Context $context, JITVariable $num1, JITVariable $num2): array
    {
        $double = $context->getTypeFromString('double');

        return [
            self::lowerOperand($context, $num1, 1, 'num1', $double),
            self::lowerOperand($context, $num2, 2, 'num2', $double),
        ];
    }

    private static function lowerOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName,
        $double
    ): Value {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitDoubleTypeErrorAndAbort(
                $context,
                $argIndex,
                $paramName,
                self::compileTimeObjectGivenLabel($context, $arg)
            );

            return $double->constReal(0.0);
        }
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $v = JitLongArg::lower($context, $arg, self::FUNCTION.'() argument');

                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->helper->loadValue($arg);
            case JITVariable::TYPE_VALUE:
                if (JitValueBox::isValueOperand($arg)) {
                    return self::unboxValueToDouble($context, $arg, $double, $argIndex, $paramName);
                }
                break;
            default:
                if (JitValueBox::isValueOperand($arg)) {
                    return self::unboxValueToDouble($context, $arg, $double, $argIndex, $paramName);
                }
        }
        throw new \LogicException(self::FUNCTION.'() only supports integers and floats in this compiler build');
    }

    private static function unboxValueToDouble(
        Context $context,
        JITVariable $arg,
        $double,
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
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $enumErrBlock = BasicBlockHelper::append($context, 'fdiv_box_enum_err');
        $afterEnum = BasicBlockHelper::append($context, 'fdiv_box_after_enum');
        $context->builder->branchIf($isEnumCase, $enumErrBlock, $afterEnum);

        $context->builder->positionAtEnd($enumErrBlock);
        self::emitDoubleTypeErrorAndAbort(
            $context,
            $argIndex,
            $paramName,
            self::compileTimeEnumCaseGivenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($afterEnum);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $readDouble = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $readLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $fromLong = $context->builder->siToFp($readLong, $double);

        return $context->builder->select(
            $isDouble,
            $readDouble,
            $context->builder->select($isLong, $fromLong, $double->constReal(0.0))
        );
    }

    private static function compileTimeObjectGivenLabel(Context $context, JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
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

    private static function compileTimeEnumCaseGivenLabel(Context $context, JITVariable $arg): string
    {
        return self::compileTimeObjectGivenLabel($context, $arg);
    }

    private static function doubleTypeError(int $argIndex, string $paramName, string $given): string
    {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type float, %s given',
            self::FUNCTION,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function emitDoubleTypeErrorAndAbort(
        Context $context,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::doubleTypeError($argIndex, $paramName, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }
}
