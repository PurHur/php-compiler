<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\NumericStringLlvm;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT ** numeric-string operand guards (#5070, zend_operators.c, #9976).
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitPowNumericOperandGuard}
 */
final class VmPowNumericOperandGuard
{
    public static function guardOperands(Context $context, Variable $base, Variable $exp): void
    {
        self::guardOperand($context, $base, $exp);
        self::guardOperand($context, $exp, $base);
    }

    private static function guardOperand(Context $context, Variable $operand, Variable $other): void
    {
        $literal = JitStringArg::compileTimeLiteral($operand);
        if (null !== $literal) {
            if (!is_numeric($literal) && !self::hasLeadingNumericPrefix($literal)) {
                self::emitTypeErrorAndAbort(
                    $context,
                    self::typeErrorMessage($operand, $other)
                );
            }

            return;
        }

        if (Variable::TYPE_STRING === $operand->type) {
            self::emitRuntimeStringGuard($context, $operand, $other);

            return;
        }

        if (Variable::TYPE_VALUE === $operand->type && JitValueBox::isValueOperand($operand)) {
            self::emitRuntimeValueBoxStringGuard($context, $operand, $other);
        }
    }

    private static function hasLeadingNumericPrefix(string $literal): bool
    {
        return (bool) preg_match('/^\s*[+-]?(?:(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $literal);
    }

    private static function emitRuntimeStringGuard(Context $context, Variable $operand, Variable $other): void
    {
        $strPtr = Variable::KIND_VALUE === $operand->kind
            ? $operand->value
            : $context->builder->load($operand->value);
        self::emitRuntimeStringPtrGuard($context, $strPtr, $operand, $other);
    }

    private static function emitRuntimeValueBoxStringGuard(
        Context $context,
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
        $okBlock = BasicBlockHelper::append($context, 'pow_vbox_non_string_ok');
        $stringBlock = BasicBlockHelper::append($context, 'pow_vbox_string_guard');
        $context->builder->branchIf($isString, $stringBlock, $okBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        self::emitRuntimeStringPtrGuard($context, $strPtr, $operand, $other);
        // Numeric string: continue (the continue block used to fall off unterminated and was
        // sealed `unreachable`, so every string operand of ** was undefined behaviour).
        $context->builder->branch($okBlock);
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitRuntimeStringPtrGuard(
        Context $context,
        Value $strPtr,
        Variable $operand,
        Variable $other
    ): void {
        // php-src _is_numeric_string_ex: only a string with no numeric prefix at all throws.
        $scratch = JitValueBox::pointer($context, JitValueBox::alloc($context));
        $status = NumericStringLlvm::call($context, $strPtr, $scratch);
        $needsTypeError = $context->builder->icmp(
            Builder::INT_EQ,
            $status,
            $context->getTypeFromString('int32')->constInt(NumericStringLlvm::NON_NUMERIC, false)
        );

        $typeErrorBlock = BasicBlockHelper::append($context, 'pow_str_type_error');
        $continueBlock = BasicBlockHelper::append($context, 'pow_str_cont');
        $context->builder->branchIf($needsTypeError, $typeErrorBlock, $continueBlock);

        $context->builder->positionAtEnd($typeErrorBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($operand, $other));
        $context->builder->positionAtEnd($continueBlock);
    }

    private static function typeErrorMessage(Variable $operand, Variable $other): string
    {
        return sprintf(
            'Unsupported operand types: %s ** %s',
            self::operandLabel($operand),
            self::operandLabel($other)
        );
    }

    private static function operandLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_HASHTABLE => 'array',
            default => 'mixed',
        };
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        // Catchable like every other operand TypeError (was raise + abort()).
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
    }
}
