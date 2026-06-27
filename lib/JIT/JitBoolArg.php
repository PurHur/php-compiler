<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitBoolArg
{
    public static function lower(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            return $context->constantFromBool(self::coerceStringLiteral($literal, $contextLabel));
        }

        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            $zero = $context->getTypeFromString('int64')->constInt(0, false);

            return $context->builder->icmp(
                Builder::INT_NE,
                $context->helper->loadValue($arg),
                $zero
            );
        }
        if (Variable::TYPE_STRING === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'string');
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxed($context, $arg, $contextLabel);
        }
        if (Variable::TYPE_NULL === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'null');
        }
        if (Variable::TYPE_HASHTABLE === $arg->type || ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'array');
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'object');
        }

        self::emitTypeErrorAndAbort($context, $contextLabel, 'mixed');

        return $context->constantFromBool(false);
    }

    /**
     * Builtin typed bool — reject int/string coercion (php-src ZEND_ARG_INFO IS_BOOL; #12585, #12586).
     */
    public static function lowerBuiltinTyped(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): Value {
        $contextLabel = sprintf('%s(): Argument #%d ($%s)', $function, $argNumber, $paramName);
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'string');
        }
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'int');
        }
        if (Variable::TYPE_STRING === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'string');
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStrict($context, $arg, $contextLabel);
        }
        if (Variable::TYPE_NULL === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'null');
        }
        if (Variable::TYPE_HASHTABLE === $arg->type || ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'array');
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'object');
        }

        self::emitTypeErrorAndAbort($context, $contextLabel, 'mixed');

        return $context->constantFromBool(false);
    }

    private static function lowerBoxedStrict(Context $context, Variable $arg, string $contextLabel): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        foreach (
            [
                [VmVariable::TYPE_ARRAY, 'array'],
                [VmVariable::TYPE_OBJECT, 'object'],
                [VmVariable::TYPE_NULL, 'null'],
                [VmVariable::TYPE_STRING, 'string'],
                [VmVariable::TYPE_INTEGER, 'int'],
                [VmVariable::TYPE_FLOAT, 'float'],
            ] as [$vmType, $label]
        ) {
            $check = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt($vmType, false));
            $ok = BasicBlockHelper::append($context, 'jit_bool_strict_ok_'.$label);
            $bad = BasicBlockHelper::append($context, 'jit_bool_strict_bad_'.$label);
            $context->builder->branchIf($check, $bad, $ok);
            $context->builder->positionAtEnd($bad);
            self::emitTypeErrorAndAbort($context, $contextLabel, $label);
            $context->builder->positionAtEnd($ok);
        }

        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );

        return $context->castToBool($context->builder->load($firstByte));
    }

    private static function lowerBoxed(Context $context, Variable $arg, string $contextLabel): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        foreach (
            [
                [VmVariable::TYPE_ARRAY, 'array'],
                [VmVariable::TYPE_OBJECT, 'object'],
                [VmVariable::TYPE_NULL, 'null'],
                [VmVariable::TYPE_STRING, 'string'],
            ] as [$vmType, $label]
        ) {
            $check = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt($vmType, false));
            $ok = BasicBlockHelper::append($context, 'jit_bool_vbox_ok_'.$label);
            $bad = BasicBlockHelper::append($context, 'jit_bool_vbox_bad_'.$label);
            $context->builder->branchIf($check, $bad, $ok);
            $context->builder->positionAtEnd($bad);
            self::emitTypeErrorAndAbort($context, $contextLabel, $label);
            $context->builder->positionAtEnd($ok);
        }

        $enumBlock = BasicBlockHelper::append($context, 'jit_bool_vbox_enum');
        $afterEnum = BasicBlockHelper::append($context, 'jit_bool_vbox_after_enum');
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $enumBlock, $afterEnum);

        $context->builder->positionAtEnd($enumBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $contextLabel,
            self::compileTimeEnumGivenLabel($context, $arg)
        );
        $context->builder->positionAtEnd($afterEnum);

        $boolBlock = BasicBlockHelper::append($context, 'jit_bool_vbox_bool');
        $longBlock = BasicBlockHelper::append($context, 'jit_bool_vbox_long');
        $mergeBlock = BasicBlockHelper::append($context, 'jit_bool_vbox_merge');
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $longBlock);

        $context->builder->positionAtEnd($boolBlock);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $boolVal = $context->castToBool($context->builder->load($firstByte));
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($longBlock);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $longVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
            $zero
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($boolVal, $boolEnd);
        $phi->addIncoming($longVal, $longEnd);

        return $phi;
    }

    private static function coerceStringLiteral(string $literal, string $contextLabel): bool
    {
        $lower = strtolower($literal);
        if (\in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (\in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
            return false;
        }

        throw new \LogicException(self::typeErrorMessage($contextLabel, 'string'));
    }

    private static function emitTypeErrorAndAbort(Context $context, string $contextLabel, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::typeErrorMessage($contextLabel, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function typeErrorMessage(string $contextLabel, string $given): string
    {
        if (preg_match('/^(.+\(\)): Argument #(\d+) \(\$([^)]+)\)$/', $contextLabel, $m)) {
            return sprintf(
                '%s(): Argument #%s ($%s) must be of type bool, %s given',
                $m[1],
                $m[2],
                $m[3],
                $given
            );
        }

        return "{$contextLabel} must be of type bool, {$given} given";
    }

    private static function compileTimeEnumGivenLabel(Context $context, Variable $arg): string
    {
        if (Variable::KIND_VALUE !== $arg->kind) {
            return 'object';
        }
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null !== $objMap && isset($objMap['class_id'])) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($arg->value, $objMap['class_id'])
            );
            if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
                $classId = (int) $classIdVal->getConstantValue();

                return $context->type->object->classNameForId($classId);
            }
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null !== $enumMap && isset($enumMap['class_id'])) {
            $classIdVal = $context->builder->load(
                $context->builder->structGep($arg->value, $enumMap['class_id'])
            );
            if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
                $classId = (int) $classIdVal->getConstantValue();

                return $context->type->object->classNameForId($classId);
            }
        }

        return 'object';
    }
}
