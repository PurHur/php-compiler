<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

/** pack() numeric format slots reject enum cases under php-src-strict (#8816). */
final class JitPackNumericGuard
{
    public static function rejectEnumCaseOperandsForLiteralFormat(
        Context $context,
        string $format,
        JITVariable ...$valueArgs
    ): void {
        $kinds = PackEngine::valueOperandKinds($format, \count($valueArgs));
        foreach ($kinds as $idx => $kind) {
            if ('int' !== $kind && 'float' !== $kind) {
                continue;
            }
            if (!isset($valueArgs[$idx])) {
                continue;
            }
            self::rejectEnumCaseOperand($context, $valueArgs[$idx], $idx + 2, $kind);
        }
    }

    public static function rejectEnumCaseOperand(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $expectedType
    ): void {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                $argIndex,
                $expectedType,
                self::compileTimeObjectGivenLabel($context, $arg)
            );

            return;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            return;
        }

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
        $enumErrBlock = BasicBlockHelper::append($context, 'pack_enum_err');
        $afterEnum = BasicBlockHelper::append($context, 'pack_after_enum');
        $context->builder->branchIf($isEnumCase, $enumErrBlock, $afterEnum);

        $context->builder->positionAtEnd($enumErrBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $argIndex,
            $expectedType,
            self::compileTimeObjectGivenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($afterEnum);
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        int $argIndex,
        string $expectedType,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                'pack(): Argument #%d ($values) must be of type %s, %s given',
                $argIndex,
                $expectedType,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function compileTimeObjectGivenLabel(Context $context, JITVariable $arg): string
    {
        if (JITVariable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null !== $objMap && isset($objMap['class_id'])) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($arg->value, $objMap['class_id'])
            );
            if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
                return $context->type->object->classNameForId((int) $classIdVal->getConstantValue());
            }
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null !== $enumMap && isset($enumMap['class_id'])) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($arg->value, $enumMap['class_id'])
            );
            if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
                return $context->type->object->classNameForId((int) $classIdVal->getConstantValue());
            }
        }

        return 'object';
    }
}
