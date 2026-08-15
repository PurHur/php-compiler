<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\boolval;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitBoolArg
{
    /** Z_PARAM_BOOL with caller strict_types parity (php-src basic_functions.c microtime/hrtime; #17025). */
    public static function lowerZParamBool(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): Value {
        InternalStrictArg::requireBool($context, $arg, $function, $paramName, $argNumber);

        return self::lower(
            $context,
            $arg,
            sprintf('%s(): Argument #%d ($%s)', $function, $argNumber, $paramName)
        );
    }

    /** Z_PARAM_BOOL coercion — null → false + E_DEPRECATED (php-src zend_API.h; #18971, #21702). */
    public static function lowerCoerceZParamBool(
        Context $context,
        Variable $arg,
        string $function,
        string $paramName,
        int $argNumber
    ): Value {
        // Compile-time null: strict TypeError then reopen insert (AOT try/catch verify; #31288 / #31245).
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            if ($context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    sprintf(
                        '%s(): Argument #%d ($%s) must be of type bool, null given',
                        $function,
                        $argNumber,
                        $paramName
                    )
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'zparam_bool_null_te_cont');

                return $context->constantFromBool(false);
            }
            self::emitNullBoolParamDeprecation($context, $function, $argNumber, $paramName);

            return $context->constantFromBool(false);
        }
        if ($context->callerStrictTypes) {
            InternalStrictArg::requireBool($context, $arg, $function, $paramName, $argNumber);
        }

        return self::lowerCoerce(
            $context,
            $arg,
            sprintf('%s(): Argument #%d ($%s)', $function, $argNumber, $paramName)
        );
    }

    public static function lowerCoerce(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
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
            return self::coerceNativeStringToBool($context, $context->helper->loadValue($arg));
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedCoerce($context, $arg, $contextLabel, true);
        }
        if (Variable::TYPE_NULL === $arg->type) {
            return $context->constantFromBool(false);
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

    public static function lower(Context $context, Variable $arg, string $contextLabel = 'argument'): Value
    {
        // Compile-time 0/1 — avoid mid-function BB diamonds after `(string)$arr[$k]` (#23427).
        if (null !== $arg->compileTimeLong) {
            return $context->constantFromBool(0 !== $arg->compileTimeLong);
        }

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
        int $argNumber,
        string $expectedType = 'bool'
    ): Value {
        $contextLabel = sprintf('%s(): Argument #%d ($%s)', $function, $argNumber, $paramName);
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'string', $expectedType);
        }
        if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_NATIVE_LONG === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'int', $expectedType);
        }
        if (Variable::TYPE_STRING === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'string', $expectedType);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedStrict($context, $arg, $contextLabel, $expectedType);
        }
        if (Variable::TYPE_NULL === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'null', $expectedType);
        }
        if (Variable::TYPE_HASHTABLE === $arg->type || ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'array', $expectedType);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, $contextLabel, 'object', $expectedType);
        }

        self::emitTypeErrorAndAbort($context, $contextLabel, 'mixed', $expectedType);

        return $context->constantFromBool(false);
    }

    private static function lowerBoxedStrict(
        Context $context,
        Variable $arg,
        string $contextLabel,
        string $expectedType = 'bool'
    ): Value {
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
            self::emitTypeErrorAndAbort($context, $contextLabel, $label, $expectedType);
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
        return self::lowerBoxedCoerce($context, $arg, $contextLabel, false);
    }

    private static function lowerBoxedCoerce(
        Context $context,
        Variable $arg,
        string $contextLabel,
        bool $coerceNull
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');

        if ($coerceNull) {
            $nullBlock = BasicBlockHelper::append($context, 'jit_bool_vbox_coerce_null');
            $afterNull = BasicBlockHelper::append($context, 'jit_bool_vbox_after_coerce_null');
            $isNull = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            );
            $context->builder->branchIf($isNull, $nullBlock, $afterNull);
            $context->builder->positionAtEnd($nullBlock);

            return $context->constantFromBool(false);
        }

        $rejectTypes = [
            [VmVariable::TYPE_ARRAY, 'array'],
            [VmVariable::TYPE_OBJECT, 'object'],
            [VmVariable::TYPE_NULL, 'null'],
            [VmVariable::TYPE_STRING, 'string'],
        ];
        if ($coerceNull) {
            $context->builder->positionAtEnd($afterNull);
            $rejectTypes = [
                [VmVariable::TYPE_ARRAY, 'array'],
                [VmVariable::TYPE_OBJECT, 'object'],
                [VmVariable::TYPE_STRING, 'string'],
            ];
        }

        foreach ($rejectTypes as [$vmType, $label]) {
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
        $isBool = boolval::isBoxedBoolTypeTag($context, $typeByte);
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

    /**
     * php-src convert_to_boolean for IS_STRING — empty / "0" → false (#4293).
     */
    private static function coerceNativeStringToBool(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $len = $context->builder->load($context->builder->structGep($strPtr, $map['length']));
        $zeroLen = $context->builder->icmp(
            Builder::INT_EQ,
            $len,
            $len->typeOf()->constInt(0, false)
        );
        $data = $context->builder->structGep($strPtr, $map['value']);
        $first = $context->builder->load($data);
        $isZeroChar = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(1, false)),
            $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(\ord('0'), false))
        );
        $isFalse = $context->builder->or($zeroLen, $isZeroChar);

        return $context->builder->select(
            $isFalse,
            $context->constantFromBool(false),
            $context->constantFromBool(true)
        );
    }

    private static function coerceStringLiteral(string $literal, string $contextLabel): bool
    {
        // php-src convert_to_boolean / zend_is_true for strings (Z_PARAM_BOOL; #4293).
        return '' !== $literal && '0' !== $literal;
    }

    /** Compile-time null → E_DEPRECATED for Z_PARAM_BOOL (php-src zend_API.h; #21702). */
    private static function emitNullBoolParamDeprecation(
        Context $context,
        string $function,
        int $argNumber,
        string $paramName
    ): void {
        $message = sprintf(
            '%s(): Passing null to parameter #%d ($%s) of type bool is deprecated',
            $function,
            $argNumber,
            $paramName
        );
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $contextLabel,
        string $given,
        string $expectedType = 'bool'
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::typeErrorMessage($contextLabel, $given, $expectedType));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function typeErrorMessage(string $contextLabel, string $given, string $expectedType = 'bool'): string
    {
        if (preg_match('/^(.+\(\)): Argument #(\d+) \(\$([^)]+)\)$/', $contextLabel, $m)) {
            return sprintf(
                '%s(): Argument #%s ($%s) must be of type %s, %s given',
                $m[1],
                $m[2],
                $m[3],
                $expectedType,
                $given
            );
        }

        return "{$contextLabel} must be of type {$expectedType}, {$given} given";
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
