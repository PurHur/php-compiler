<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/**
 * Iterable builtin operand guards — TypeError before backing coercion (#6232, php-src-strict).
 *
 * php-src: ext/spl/php_spl.c — iterator_count / iterator_apply iterable checks
 */
final class JitIterableArg
{
    public static function iterableTypeErrorMessage(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            IterableCheck::TYPE_LABEL,
            $given
        );
    }

    public static function emitIterableTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            self::iterableTypeErrorMessage($function, $argIndex, $paramName, $given)
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    /**
     * Reject non-iterable operands at JIT lowering time (enum cases name enum class, not mixed).
     *
     * @return bool false when compile-time rejection IR was emitted (caller must not continue lowering)
     */
    public static function guardIterableOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'iterator'
    ): bool {
        if (self::isCompileTimeIterable($context, $arg)) {
            return true;
        }
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel);

            return false;
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::guardValueBoxOperand($context, $arg, $function, $argIndex, $paramName);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitIterableTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::compileTimeEnumClassName($context, $arg)
                    ?? JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return false;
        }
        self::emitIterableTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            JitOperandTypeLabel::givenLabel($context, $arg)
        );

        return false;
    }

    private static function isCompileTimeIterable(Context $context, Variable $arg): bool
    {
        if ($arg->type & Variable::IS_NATIVE_ARRAY) {
            return true;
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (GeneratorHelper::isGeneratorVariable($arg)) {
            return true;
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $arg, null)) {
            return true;
        }
        if (Variable::TYPE_VALUE !== $arg->type) {
            return false;
        }
        $constantType = self::constantValueBoxType($context, $arg);
        if (null === $constantType) {
            return false;
        }
        if (VmVariable::TYPE_ARRAY === $constantType) {
            return true;
        }
        if (VmVariable::TYPE_OBJECT === $constantType) {
            return GeneratorHelper::isGeneratorVariable($arg)
                || IteratorProtocolHelper::canLowerIteratorProtocol($context, $arg, null);
        }

        return false;
    }

    private static function constantValueBoxType(Context $context, Variable $arg): ?int
    {
        if (Variable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $map = $context->structFieldMap['__value__'] ?? null;
        if (null === $map || !isset($map['type'])) {
            return null;
        }
        $typeByte = $context->builder->load(
            $context->builder->structGep($arg->value, $map['type'])
        );
        if (!method_exists($typeByte, 'isConstant') || !$typeByte->isConstant()) {
            return null;
        }

        return (int) $typeByte->getConstantValue();
    }

    private static function guardValueBoxOperand(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $constantType = self::constantValueBoxType($context, $arg);
        if (null !== $constantType) {
            if (VmVariable::TYPE_ARRAY === $constantType) {
                return true;
            }
            if (VmVariable::TYPE_ENUM_CASE === $constantType
                || VmVariable::TYPE_OBJECT === $constantType) {
                $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg)
                    ?? JitOperandTypeLabel::givenLabel($context, $arg);
                self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumLabel);

                return false;
            }
            self::emitIterableTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return false;
        }

        self::emitRuntimeValueBoxEnumGuard($context, $arg, $function, $argIndex, $paramName);

        return true;
    }

    private static function emitRuntimeValueBoxEnumGuard(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $okBlock = BasicBlockHelper::append($context, 'iterable_arg_ok');
        $checkEnumBlock = BasicBlockHelper::append($context, 'iterable_arg_check_enum');
        $context->builder->branchIf($isArray, $okBlock, $checkEnumBlock);

        $context->builder->positionAtEnd($checkEnumBlock);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);
        $enumBlock = BasicBlockHelper::append($context, 'iterable_arg_enum');
        $context->builder->branchIf($isEnumCase, $enumBlock, $okBlock);

        $context->builder->positionAtEnd($enumBlock);
        self::emitIterableTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object');

        $context->builder->positionAtEnd($okBlock);
    }
}

