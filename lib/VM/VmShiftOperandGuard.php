<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;

/**
 * SSOT for JIT shift operand guards (#30138, zend_operators.c, #9976).
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitShiftOperandGuard}
 */
final class VmShiftOperandGuard
{
    public static function guardOperands(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): void {
        $message = self::compileTimeMessage($context, $opCode, $left, $right);
        if (null !== $message) {
            self::emitTypeErrorAndAbort($context, $message);

            return;
        }
        self::guardOperand($context, $opCode, $left, $right);
        self::guardOperand($context, $opCode, $right, $left);
        ShiftOperandRuntime::guardRuntimeOperands($context, $opCode, $left, $right);
    }

    private static function compileTimeMessage(
        Context $context,
        int $opCode,
        Variable $left,
        Variable $right
    ): ?string {
        $leftBad = self::compileTimeInvalidLabel($context, $left);
        $rightBad = self::compileTimeInvalidLabel($context, $right);
        if (null === $leftBad && null === $rightBad) {
            return null;
        }

        return sprintf(
            'Unsupported operand types: %s %s %s',
            $leftBad ?? JitOperandTypeLabel::givenLabel($context, $left),
            ShiftOperandJitHelper::operatorSymbol($opCode),
            $rightBad ?? JitOperandTypeLabel::givenLabel($context, $right)
        );
    }

    private static function compileTimeInvalidLabel(Context $context, Variable $var): ?string
    {
        $literal = JitStringArg::compileTimeLiteral($var);
        if (null !== $literal) {
            return is_numeric($literal) ? null : 'string';
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $classLabel = JitOperandTypeLabel::givenLabel($context, $var);
            if ('object' !== $classLabel) {
                return $classLabel;
            }
        }

        return null;
    }

    private static function guardOperand(
        Context $context,
        int $opCode,
        Variable $operand,
        Variable $other
    ): void {
        $literal = JitStringArg::compileTimeLiteral($operand);
        if (null !== $literal) {
            if (!is_numeric($literal)) {
                self::emitTypeErrorAndAbort(
                    $context,
                    self::typeErrorMessage($context, $opCode, $operand, $other)
                );
            }

            return;
        }

        if (Variable::TYPE_STRING === $operand->type) {
            self::emitRuntimeStringGuard($context, $opCode, $operand, $other);

            return;
        }

        if (Variable::TYPE_OBJECT === $operand->type) {
            $classLabel = JitOperandTypeLabel::givenLabel($context, $operand);
            if ('object' !== $classLabel) {
                self::emitTypeErrorAndAbort(
                    $context,
                    self::typeErrorMessage($context, $opCode, $operand, $other)
                );
            }
        }

        if (Variable::TYPE_VALUE === $operand->type && JitValueBox::isValueOperand($operand)) {
            self::emitRuntimeValueBoxStringGuard($context, $opCode, $operand, $other);
            ShiftOperandRuntime::emitValueBoxObjectReject($context, $opCode, $operand, $other);
        }
    }

    private static function emitRuntimeStringGuard(
        Context $context,
        int $opCode,
        Variable $operand,
        Variable $other
    ): void {
        $strPtr = Variable::KIND_VALUE === $operand->kind
            ? $operand->value
            : $context->builder->load($operand->value);
        self::emitRuntimeStringPtrGuard($context, $opCode, $strPtr, $operand, $other);
    }

    private static function emitRuntimeValueBoxStringGuard(
        Context $context,
        int $opCode,
        Variable $operand,
        Variable $other
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $operand);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringTy = $i8->constInt(Variable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTy);
        $okBlock = BasicBlockHelper::append($context, 'shift_vbox_non_string_ok');
        $stringBlock = BasicBlockHelper::append($context, 'shift_vbox_string_guard');
        $context->builder->branchIf($isString, $stringBlock, $okBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        self::emitRuntimeStringPtrGuard($context, $opCode, $strPtr, $operand, $other);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitRuntimeStringPtrGuard(
        Context $context,
        int $opCode,
        $strPtr,
        Variable $operand,
        Variable $other
    ): void {
        $isNumeric = VmValueCompare::stringIsNumeric($context, $strPtr);
        $noLeadingPrefix = VmValueCompare::stringHasNoLeadingIntegerPrefix($context, $strPtr);
        $needsTypeError = $context->builder->and($noLeadingPrefix, $context->builder->not($isNumeric));

        $typeErrorBlock = BasicBlockHelper::append($context, 'shift_str_type_error');
        $continueBlock = BasicBlockHelper::append($context, 'shift_str_cont');
        $context->builder->branchIf($needsTypeError, $typeErrorBlock, $continueBlock);

        $context->builder->positionAtEnd($typeErrorBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($context, $opCode, $operand, $other));
        $context->builder->positionAtEnd($continueBlock);
    }

    private static function typeErrorMessage(
        Context $context,
        int $opCode,
        Variable $operand,
        Variable $other
    ): string {
        return sprintf(
            'Unsupported operand types: %s %s %s',
            JitOperandTypeLabel::givenLabel($context, $operand),
            ShiftOperandJitHelper::operatorSymbol($opCode),
            JitOperandTypeLabel::givenLabel($context, $other)
        );
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }
}
