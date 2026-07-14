<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\OpCode;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/** Shared math coercion helpers for ext/standard (issue #3578) and base_convert (#3173). */
final class VmMath
{
    private const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * PHP 8.4+ forward profile: Z_PARAM_LONG null is TypeError (ext/standard/math.c; #18850).
     */
    public static function requiresForwardProfileStrictNumericNull(): bool
    {
        return VmString::requiresForwardProfileStrictStringNull();
    }

    public static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('Math builtins only support integers and floats in this compiler build');
    }

    public static function toInt(Variable $v): int
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return (int) $v->toFloat();
        }
        throw new \LogicException('Math builtins only support integers and floats in this compiler build');
    }

    /**
     * Truncate a finite float toward zero like Zend Z_PARAM_LONG (no PHP 8.4 implicit-cast deprecation).
     */
    public static function floatToZendLong(float $value): int
    {
        if ($value >= 0.0) {
            return (int) floor($value);
        }

        return (int) ceil($value);
    }

    /** php-src zend_operators.c — float→long precision loss (issue #10440). */
    public static function floatLosesIntPrecision(float $value): bool
    {
        if (!\is_finite($value)) {
            return false;
        }

        return $value !== (float) self::floatToZendLong($value);
    }

    public static function floatToIntPrecisionWarningMessage(float $value): string
    {
        return \sprintf('Implicit conversion from float %s to int loses precision', $value);
    }

    public static function warnFloatToIntPrecisionLoss(
        float $value,
        \PHPCompiler\VM\Context $vmContext,
        ?Frame $frame = null
    ): void {
        if (!self::floatLosesIntPrecision($value)) {
            return;
        }
        $vmContext->errors->triggerErrorWithHandlerFirst(
            self::floatToIntPrecisionWarningMessage($value),
            ErrorReporter::E_DEPRECATED,
            null !== $frame && '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $vmContext,
            $frame
        );
    }

    /**
     * Z_PARAM_BOOL with caller strict_types parity (php-src basic_functions.c microtime/hrtime; #17025).
     *
     * @throws \TypeError when strict_types rejects null/non-bool operands
     */
    public static function parseBoolBuiltinArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): bool {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireBool($frame, $argIndex, $function, $paramName)->toBool();
        }

        return self::parseBoolBuiltinArg(
            $frame->calledArgs[$argIndex],
            $function,
            $userArgIndex,
            $paramName
        );
    }

    public static function parseBoolBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseBoolBuiltinArg($var, $function, $argIndex, $paramName);
        switch ($var->type) {
            case Variable::TYPE_BOOLEAN:
                return $var->toBool();
            case Variable::TYPE_INTEGER:
                return 0 !== $var->toInt();
            case Variable::TYPE_FLOAT:
                return 0.0 !== $var->toFloat();
            case Variable::TYPE_NULL:
                return false;
            case Variable::TYPE_STRING:
                return self::coerceBoolStringLiteral($var->toString(), $function, $argIndex, $paramName);
            case Variable::TYPE_ARRAY:
                throw new \TypeError(self::boolBuiltinTypeError($function, $argIndex, $paramName, 'array'));
            case Variable::TYPE_OBJECT:
                throw new \TypeError(
                    self::boolBuiltinTypeError(
                        $function,
                        $argIndex,
                        $paramName,
                        $var->toObject()->class->name
                    )
                );
            default:
                throw new \TypeError(
                    self::boolBuiltinTypeError(
                        $function,
                        $argIndex,
                        $paramName,
                        self::vmTypeName($var->type)
                    )
                );
        }
    }

    /**
     * IS_BOOL internal params without coercion (php-src ZEND_ARG_INFO; #12585, #14763 array_slice preserve_keys).
     *
     * @throws \TypeError when operand is not boolean
     */
    public static function requireBuiltinBoolArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseBoolBuiltinArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            $given = Variable::TYPE_OBJECT === $var->type
                ? $var->toObject()->class->name
                : self::vmTypeName($var->type);
            throw new \TypeError(self::boolBuiltinTypeError($function, $argIndex, $paramName, $given));
        }

        return $var->toBool();
    }

    /**
     * ?bool internal params that coerce like Z_PARAM_BOOL (php-src basic_functions.c; #12677).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function parseNullableBoolBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): ?bool {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return self::parseBoolBuiltinArg($var, $function, $argIndex, $paramName);
    }

    /**
     * Z_PARAM_NUMBER-style coercion for int|float builtins (php-src math.c abs/ceil/floor/round; #5613).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function parseNumberBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName,
        ?Frame $frame = null
    ): int|float {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseNumberBuiltinArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::numberBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(
                self::numberBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    $var->toObject()->class->name
                )
            );
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $var->type) {
            if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(self::numberBuiltinTypeError($function, $argIndex, $paramName, 'null'));
            }
            VmNullNumberParamDeprecation::emit($frame, $function, $argIndex, $paramName);

            return 0;
        }
        if (Variable::TYPE_STRING === $var->type) {
            $s = $var->toString();
            if ('' === $s || !is_numeric($s)) {
                throw new \TypeError(self::numberBuiltinTypeError($function, $argIndex, $paramName, 'string'));
            }
            if (!str_contains($s, '.') && !str_contains($s, 'e') && !str_contains($s, 'E')) {
                return (int) $s;
            }

            return (float) $s;
        }

        throw new \TypeError(
            self::numberBuiltinTypeError($function, $argIndex, $paramName, self::vmTypeName($var->type))
        );
    }

    /**
     * Z_PARAM_LONG-style coercion for int-only builtins (php-src math.c; #4982 intdiv, #5360 float truncation).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    /**
     * Z_PARAM_LONG_OR_NULL-style coercion (php-src basic_functions.c error_reporting; #5917).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function parseNullableIntBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): ?int {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        self::rejectEnumCaseNullableIntBuiltinArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::nullableIntBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::nullableIntBuiltinTypeError(
                $function,
                $argIndex,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            $f = $var->toFloat();
            if (!\is_finite($f)) {
                throw new \TypeError(self::nullableIntBuiltinTypeError($function, $argIndex, $paramName, 'float'));
            }

            return self::floatToZendLong($f);
        }

        return self::parseNullableLongBuiltinArgCore($var, $function, $argIndex, $paramName);
    }

    public static function parseIntBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseIntBuiltinArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::intBuiltinTypeError(
                $function,
                $argIndex,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            $f = $var->toFloat();
            if (!\is_finite($f)) {
                throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'float'));
            }

            return self::floatToZendLong($f);
        }

        return self::parseLongBuiltinArgCore($var, $function, $argIndex, $paramName);
    }

    /**
     * int builtin args with strict_types TypeError on float (#10468, zend_verify_arg_type).
     */
    public static function parseIntBuiltinArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): int {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireInt($frame, $argIndex, $function, $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toInt();
        }
        $var = $frame->calledArgs[$argIndex];
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_FLOAT === $resolved->type && null !== $frame->vmContext) {
            self::warnFloatToIntPrecisionLoss($resolved->toFloat(), $frame->vmContext, $frame);
        }

        return self::parseIntBuiltinArg($var, $function, $userArgIndex, $paramName);
    }

    /**
     * Nullable int builtin args with strict_types TypeError on float/string (#11286, zend_verify_arg_type).
     */
    public static function parseNullableIntBuiltinArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): ?int {
        $var = $frame->calledArgs[$argIndex];
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireNullableInt($frame, $argIndex, $function, $paramName);

            return Variable::TYPE_NULL === $resolved->type ? null : $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type && null !== $frame->vmContext) {
            self::warnFloatToIntPrecisionLoss($resolved->toFloat(), $frame->vmContext, $frame);
        }

        return self::parseNullableIntBuiltinArg($var, $function, $userArgIndex, $paramName);
    }

    /**
     * chr() codepoint coercion (php-src ext/standard/string.c php_chr; #5085).
     *
     * Float operands truncate toward zero; non-numeric strings throw TypeError.
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function parseChrCodepoint(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseIntBuiltinArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(self::intBuiltinTypeError(
                $function,
                $argIndex,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return self::floatToZendLong($var->toFloat());
        }

        return self::parseLongBuiltinArgCore($var, $function, $argIndex, $paramName);
    }

    private static function parseNullableLongBuiltinArgCore(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(self::nullableIntBuiltinTypeError($function, $argIndex, $paramName, 'string'));
                }

                return (int) $s;
            default:
                throw new \TypeError(
                    self::nullableIntBuiltinTypeError($function, $argIndex, $paramName, self::vmTypeName($var->type))
                );
        }
    }

    private static function parseLongBuiltinArgCore(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): int {
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_NULL:
                if (self::requiresForwardProfileStrictNumericNull()) {
                    throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'null'));
                }

                return 0;
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'string'));
                }

                return (int) $s;
            default:
                throw new \TypeError(
                    self::intBuiltinTypeError($function, $argIndex, $paramName, self::vmTypeName($var->type))
                );
        }
    }

    /**
     * Z_PARAM_DOUBLE coercion for float builtins (php-src math.c fdiv; #6185).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function parseDoubleBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): float {
        $var = $var->resolveIndirect();
        self::rejectEnumCaseDoubleBuiltinArg($var, $function, $argIndex, $paramName);
        if (Variable::TYPE_ARRAY === $var->type) {
            throw new \TypeError(self::doubleBuiltinTypeError($function, $argIndex, $paramName, 'array'));
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            throw new \TypeError(
                self::doubleBuiltinTypeError(
                    $function,
                    $argIndex,
                    $paramName,
                    $var->toObject()->class->name
                )
            );
        }
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return (float) $var->toInt();
            case Variable::TYPE_FLOAT:
                return $var->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1.0 : 0.0;
            case Variable::TYPE_NULL:
                return 0.0;
            case Variable::TYPE_STRING:
                $s = $var->toString();
                if ('' === $s || !is_numeric($s)) {
                    throw new \TypeError(self::doubleBuiltinTypeError($function, $argIndex, $paramName, 'string'));
                }

                return (float) $s;
            default:
                throw new \TypeError(
                    self::doubleBuiltinTypeError(
                        $function,
                        $argIndex,
                        $paramName,
                        self::vmTypeName($var->type)
                    )
                );
        }
    }

    /**
     * float builtin args with strict_types TypeError on string (#11497, ext/standard/math.c).
     */
    public static function parseStrictFloatBuiltinArgForFrame(
        Frame $frame,
        string $function,
        int $userArgIndex,
        string $paramName
    ): float {
        if (InternalStrictArg::isCallerStrict($frame)) {
            $arg = InternalStrictArg::requireFloat($frame, 0, $function, $paramName);
            if (Variable::TYPE_INTEGER === $arg->type) {
                return (float) $arg->toInt();
            }

            return $arg->toFloat();
        }

        return self::parseDoubleBuiltinArg(
            $frame->calledArgs[0],
            $function,
            $userArgIndex,
            $paramName
        );
    }

    /**
     * Z_PARAM_NUMBER rejects enum cases (php-src ext/standard/math.c; #5613).
     *
     * @throws \TypeError
     */
    private static function rejectEnumCaseNumberBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        self::rejectEnumCaseBuiltinArg($var, $function, $argIndex, $paramName, 'number');
    }

    /**
     * Z_PARAM_LONG rejects enum cases (php-src ext/standard/string.c chr/ord; #5673, #5836).
     *
     * @throws \TypeError
     */
    private static function rejectEnumCaseIntBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        self::rejectEnumCaseBuiltinArg($var, $function, $argIndex, $paramName, 'int');
    }

    /**
     * Z_PARAM_LONG_OR_NULL rejects enum cases (php-src basic_functions.c error_reporting; #5917).
     *
     * @throws \TypeError
     */
    private static function rejectEnumCaseNullableIntBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        $given = null !== $enumClass ? $enumClass->name : 'object';

        throw new \TypeError(self::nullableIntBuiltinTypeError($function, $argIndex, $paramName, $given));
    }

    /**
     * Z_PARAM_BOOL rejects enum cases (php-src basic_functions.c microtime/hrtime; #6149).
     *
     * @throws \TypeError
     */
    private static function rejectEnumCaseBoolBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        self::rejectEnumCaseBuiltinArg($var, $function, $argIndex, $paramName, 'bool');
    }

    /**
     * Z_PARAM_DOUBLE rejects enum cases (php-src ext/standard/math.c fdiv; #6185).
     *
     * @throws \TypeError
     */
    private static function rejectEnumCaseDoubleBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        self::rejectEnumCaseBuiltinArg($var, $function, $argIndex, $paramName, 'float');
    }

    /**
     * @throws \TypeError
     */
    private static function rejectEnumCaseBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        $given = null !== $enumClass ? $enumClass->name : 'object';
        $message = match ($expectedType) {
            'int' => self::intBuiltinTypeError($function, $argIndex, $paramName, $given),
            'float' => self::doubleBuiltinTypeError($function, $argIndex, $paramName, $given),
            'number' => self::numberBuiltinTypeError($function, $argIndex, $paramName, $given),
            default => self::boolBuiltinTypeError($function, $argIndex, $paramName, $given),
        };

        throw new \TypeError($message);
    }

    private static function coerceBoolStringLiteral(
        string $literal,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $lower = strtolower($literal);
        if (\in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (\in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
            return false;
        }

        throw new \TypeError(self::boolBuiltinTypeError($function, $argIndex, $paramName, 'string'));
    }

    private static function boolBuiltinTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type bool, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function intBuiltinTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function nullableIntBuiltinTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type ?int, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function numberBuiltinTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type int|float, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function doubleBuiltinTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type float, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }

    /**
     * int**int — preserves int when Zend overflow rules allow (#3678, #9515).
     *
     * @return int|float
     */
    public static function powInt(int $base, int $exp): int|float
    {
        return $base ** $exp;
    }

    /**
     * pow() — delegate to {@see Variable::numericOp()} ** semantics (php-src math.c / zend_operators.c; #3678, #4888).
     */
    public static function applyPow(Variable $returnVar, Variable $base, Variable $exp, ?Frame $frame = null): void
    {
        $returnVar->reset();
        $vm = null !== $frame && null !== $frame->vmContext ? $frame->vmContext->runtime->vm : null;
        $returnVar->numericOp(OpCode::TYPE_POW, $base, $exp, $vm, $frame);
    }

    /**
     * fpow() — always returns float (php-src ext/standard/math.c zend_fpow; #7045).
     */
    public static function fpow(float $num, float $exponent): float
    {
        return \pow($num, $exponent);
    }

    /** fadd() — IEEE-754 float addition (php-src ext/standard/math.c zend_fadd; #17290). */
    public static function fadd(float $num1, float $num2): float
    {
        if (\function_exists('fadd')) {
            return \fadd($num1, $num2);
        }

        return $num1 + $num2;
    }

    /** fsub() — IEEE-754 float subtraction (php-src ext/standard/math.c zend_fsub; #17290). */
    public static function fsub(float $num1, float $num2): float
    {
        if (\function_exists('fsub')) {
            return \fsub($num1, $num2);
        }

        return $num1 - $num2;
    }

    /** fmul() — IEEE-754 float multiplication (php-src ext/standard/math.c zend_fmul; #17290). */
    public static function fmul(float $num1, float $num2): float
    {
        if (\function_exists('fmul')) {
            return \fmul($num1, $num2);
        }

        return $num1 * $num2;
    }

    /** fmod() — floating-point remainder (php-src ext/standard/math.c). */
    public static function fmod(float $num1, float $num2): float
    {
        return \fmod($num1, $num2);
    }

    /** hypot() — sqrt(x² + y²) without overflow (php-src ext/standard/math.c). */
    public static function hypot(float $x, float $y): float
    {
        return \hypot($x, $y);
    }

    /** sin() — sine (php-src ext/standard/math.c). */
    public static function sin(float $num): float
    {
        return \sin($num);
    }

    /** cos() — cosine (php-src ext/standard/math.c). */
    public static function cos(float $num): float
    {
        return \cos($num);
    }

    /** tan() — tangent (php-src ext/standard/math.c). */
    public static function tan(float $num): float
    {
        return \tan($num);
    }

    /** cosh() — hyperbolic cosine (php-src ext/standard/math.c). */
    public static function cosh(float $num): float
    {
        return \cosh($num);
    }

    /** sinh() — hyperbolic sine (php-src ext/standard/math.c). */
    public static function sinh(float $num): float
    {
        return \sinh($num);
    }

    /** tanh() — hyperbolic tangent (php-src ext/standard/math.c). */
    public static function tanh(float $num): float
    {
        return \tanh($num);
    }

    /** asinh() — inverse hyperbolic sine (php-src ext/standard/math.c). */
    public static function asinh(float $num): float
    {
        return \asinh($num);
    }

    /** acosh() — inverse hyperbolic cosine (php-src ext/standard/math.c). */
    public static function acosh(float $num): float
    {
        return \acosh($num);
    }

    /** atanh() — inverse hyperbolic tangent (php-src ext/standard/math.c). */
    public static function atanh(float $num): float
    {
        return \atanh($num);
    }

    /** exp() — natural exponential (php-src ext/standard/math.c). */
    public static function exp(float $num): float
    {
        return \exp($num);
    }

    /** sqrt() — square root (php-src ext/standard/math.c). */
    public static function sqrt(float $num): float
    {
        return \sqrt($num);
    }

    /** log() — natural logarithm (php-src ext/standard/math.c). */
    public static function log(float $num): float
    {
        return \log($num);
    }

    /** log10() — base-10 logarithm (php-src ext/standard/math.c). */
    public static function log10(float $num): float
    {
        return \log10($num);
    }

    /** log1p() — log(1+x) (php-src ext/standard/math.c). */
    public static function log1p(float $num): float
    {
        return \log1p($num);
    }

    /** expm1() — exp(x)-1 (php-src ext/standard/math.c). */
    public static function expm1(float $num): float
    {
        return \expm1($num);
    }

    /** floor() — round toward negative infinity (php-src ext/standard/math.c). */
    public static function floor(float $num): float
    {
        return \floor($num);
    }

    /** ceil() — round toward positive infinity (php-src ext/standard/math.c). */
    public static function ceil(float $num): float
    {
        return \ceil($num);
    }

    /** asin() — arc sine (php-src ext/standard/math.c). */
    public static function asin(float $num): float
    {
        return \asin($num);
    }

    /** acos() — arc cosine (php-src ext/standard/math.c). */
    public static function acos(float $num): float
    {
        return \acos($num);
    }

    /** atan() — arc tangent (php-src ext/standard/math.c). */
    public static function atan(float $num): float
    {
        return \atan($num);
    }

    /** deg2rad() — degrees to radians (php-src ext/standard/math.c). */
    public static function deg2rad(float $num): float
    {
        return (\M_PI / 180.0) * $num;
    }

    /** rad2deg() — radians to degrees (php-src ext/standard/math.c). */
    public static function rad2deg(float $num): float
    {
        return (180.0 / \M_PI) * $num;
    }

    /** atan2() — arc tangent of y/x (php-src ext/standard/math.c). */
    public static function atan2(float $y, float $x): float
    {
        return \atan2($y, $x);
    }

    /**
     * nextafter() — IEEE next representable float toward $next (php-src ext/standard/math.c; #9241).
     */
    public static function nextafter(float $num, float $next): float
    {
        if (\is_nan($num)) {
            return $num;
        }
        if (\is_nan($next)) {
            return $next;
        }
        if ($num === $next) {
            return $next;
        }
        if (0.0 === $num || -0.0 === $num) {
            if ($next > 0.0) {
                return \unpack('d', \pack('P', 1))[1];
            }

            return \unpack('d', \pack('P', 0x8000000000000001))[1];
        }
        $bits = \unpack('P', \pack('d', $num))[1];
        if (($num > 0.0) === ($next > $num)) {
            ++$bits;
        } else {
            --$bits;
        }

        return \unpack('d', \pack('P', $bits))[1];
    }

    /** IEEE fmin pair (php-src ext/standard/math.c zend_fmin; #11728). */
    public static function fminPair(float $a, float $b): float
    {
        if (\is_nan($a)) {
            return $b;
        }
        if (\is_nan($b)) {
            return $a;
        }

        return $a < $b ? $a : $b;
    }

    /** IEEE fmax pair (php-src ext/standard/math.c zend_fmax; #11728). */
    public static function fmaxPair(float $a, float $b): float
    {
        if (\is_nan($a)) {
            return $b;
        }
        if (\is_nan($b)) {
            return $a;
        }

        return $a > $b ? $a : $b;
    }

    /** @return float fractional part; writes integer part to $intPart (php-src modf). */
    public static function modf(float $num, float &$intPart): float
    {
        if (\is_nan($num) || \is_infinite($num)) {
            $intPart = $num;

            return $num;
        }
        $intPart = $num >= 0.0 ? \floor($num) : \ceil($num);

        return $num - $intPart;
    }

    /** @return float normalized fraction; writes binary exponent to $exp (php-src frexp). */
    public static function frexp(float $num, int &$exp): float
    {
        if (0.0 === $num) {
            $exp = 0;

            return 0.0;
        }
        if (\is_nan($num) || \is_infinite($num)) {
            $exp = 0;

            return $num;
        }
        $abs = \abs($num);
        $exp = (int) \floor(\log($abs, 2.0));
        $frac = $num / (2 ** $exp);
        if (\abs($frac) >= 1.0) {
            $frac /= 2.0;
            ++$exp;
        }
        if (0.0 !== $frac && \abs($frac) < 0.5) {
            $frac *= 2.0;
            --$exp;
        }

        return $frac;
    }

    public static function ldexp(float $num, int $exp): float
    {
        if (0.0 === $num || 0 === $exp) {
            return $num;
        }
        if (\is_nan($num) || \is_infinite($num)) {
            return $num;
        }

        return $num * (2 ** $exp);
    }

    /**
     * php-src: ext/standard/math.c — base_convert()
     *
     * JIT/AOT lower via {@see MathBaseConvertRuntime} → VmMath PHP (#9584).
     */
    public static function baseConvert(string $number, int $fromBase, int $toBase): string
    {
        if ($fromBase < 2 || $fromBase > 36) {
            throw new \ValueError('base_convert(): Argument #2 ($from_base) must be between 2 and 36 (inclusive)');
        }
        if ($toBase < 2 || $toBase > 36) {
            throw new \ValueError('base_convert(): Argument #3 ($to_base) must be between 2 and 36 (inclusive)');
        }

        $value = self::baseToZval($number, $fromBase);

        return is_float($value)
            ? self::doubleToBase($value, $toBase)
            : self::longToBase((int) $value, $toBase);
    }

    /**
     * Radix for intval() / zend_strtol when $base === 0 (prefix autodetect).
     *
     * php-src: Zend/zend_operators.c — zend_strtol()
     */
    public static function autodetectBase(string $str): int
    {
        $len = \strlen($str);
        $start = 0;
        while ($start < $len && \ctype_space($str[$start])) {
            ++$start;
        }
        if ($start >= $len || '0' !== $str[$start]) {
            return 10;
        }
        if ($start + 1 < $len) {
            $c1 = $str[$start + 1];
            if ('x' === $c1 || 'X' === $c1) {
                return 16;
            }
            if ('b' === $c1 || 'B' === $c1) {
                return 2;
            }
        }

        return 8;
    }

    /**
     * @return int|float
     */
    public static function baseToZval(string $str, int $base): int|float
    {
        $len = \strlen($str);
        $start = 0;
        $end = $len;

        while ($start < $end && \ctype_space($str[$start])) {
            ++$start;
        }
        while ($end > $start && \ctype_space($str[$end - 1])) {
            --$end;
        }

        if ($end - $start >= 2) {
            if (16 === $base && '0' === $str[$start] && ('x' === $str[$start + 1] || 'X' === $str[$start + 1])) {
                $start += 2;
            } elseif (8 === $base && '0' === $str[$start] && ('o' === $str[$start + 1] || 'O' === $str[$start + 1])) {
                $start += 2;
            } elseif (2 === $base && '0' === $str[$start] && ('b' === $str[$start + 1] || 'B' === $str[$start + 1])) {
                $start += 2;
            }
        }

        $num = 0;
        $cutoff = intdiv(\PHP_INT_MAX, $base);
        $cutlim = \PHP_INT_MAX % $base;
        $invalidChars = 0;

        for ($i = $start; $i < $end; ++$i) {
            $digit = self::radixDigit($str[$i], $base);
            if (null === $digit) {
                ++$invalidChars;

                continue;
            }

            if ($num < $cutoff || ($num === $cutoff && $digit <= $cutlim)) {
                $num = $num * $base + $digit;

                continue;
            }

            if ($invalidChars > 0) {
                @\trigger_error(
                    'Invalid characters passed for attempted conversion, these have been ignored',
                    \E_USER_DEPRECATED
                );
            }

            return self::baseToZvalFloat($str, $start, $end, $base);
        }

        if ($invalidChars > 0) {
            @\trigger_error(
                'Invalid characters passed for attempted conversion, these have been ignored',
                \E_USER_DEPRECATED
            );
        }

        return $num;
    }

    /**
     * Parse a radix string as float when integer range is exhausted (php-src strtod overflow path).
     *
     * Chunked digit accumulation preserves IEEE mantissa for values > PHP_INT_MAX (#10452).
     */
    private static function baseToZvalFloat(string $str, int $start, int $end, int $base): float
    {
        $chunkDigits = self::maxFloatParseChunkDigits($base);
        $fnum = 0.0;
        $i = $start;
        while ($i < $end) {
            $chunk = 0;
            $count = 0;
            while ($i < $end && $count < $chunkDigits) {
                $digit = self::radixDigit($str[$i], $base);
                ++$i;
                if (null === $digit) {
                    continue;
                }
                $chunk = $chunk * $base + $digit;
                ++$count;
            }
            if (0 === $count) {
                continue;
            }
            for ($k = 0; $k < $count; ++$k) {
                $fnum *= $base;
            }
            $fnum += $chunk;
        }

        return $fnum;
    }

    /** Max digits per chunk so base^digits fits in signed int (#10452). */
    private static function maxFloatParseChunkDigits(int $base): int
    {
        $digits = 1;
        $power = $base;
        while ($power <= intdiv(\PHP_INT_MAX, $base)) {
            $power *= $base;
            ++$digits;
        }

        return $digits;
    }

    private static function radixDigit(string $c, int $base): ?int
    {
        if ($c >= '0' && $c <= '9') {
            $digit = (int) ($c - '0');
        } elseif ($c >= 'A' && $c <= 'Z') {
            $digit = (int) (\ord($c) - \ord('A') + 10);
        } elseif ($c >= 'a' && $c <= 'z') {
            $digit = (int) (\ord($c) - \ord('a') + 10);
        } else {
            return null;
        }

        return $digit >= $base ? null : $digit;
    }

    public static function assignRadixToReturn(?Variable $returnVar, string $str, int $base): void
    {
        if (null === $returnVar) {
            return;
        }
        $result = self::baseToZval($str, $base);
        if (\is_int($result)) {
            $returnVar->int($result);
        } else {
            $returnVar->float($result);
        }
    }

    public static function longToBase(int $arg, int $base): string
    {
        if ($base < 2 || $base > 36) {
            return '';
        }

        if (0 === $arg) {
            return '0';
        }

        $negative = $arg < 0;
        $n = $negative ? abs($arg) : $arg;

        $buf = '';
        while ($n > 0) {
            $buf = self::DIGITS[$n % $base].$buf;
            $n = intdiv($n, $base);
        }

        return $negative ? '-'.$buf : $buf;
    }

    public static function doubleToBase(float $fvalue, int $base): string
    {
        if ($base < 2 || $base > 36) {
            return '';
        }

        if ($fvalue === \INF || $fvalue === -\INF) {
            throw new \ValueError(\sprintf('An infinite value cannot be converted to base %d', $base));
        }

        $fvalue = floor($fvalue);
        if (0.0 === $fvalue) {
            return '0';
        }

        $negative = $fvalue < 0.0;
        if ($negative) {
            $fvalue = -$fvalue;
        }

        $buf = '';
        while ($fvalue >= 1.0) {
            $digit = (int) fmod($fvalue, (float) $base);
            $buf = self::DIGITS[$digit].$buf;
            $fvalue /= (float) $base;
        }

        return $negative ? '-'.$buf : $buf;
    }

    /**
     * clamp() — PHP 8.3 (ext/standard/math.c php_math_clamp).
     */
    public static function clamp(
        Variable $value,
        Variable $min,
        Variable $max,
        Variable $result,
        string $function = 'clamp'
    ): void {
        $value = $value->resolveIndirect();
        $min = $min->resolveIndirect();
        $max = $max->resolveIndirect();

        if (Variable::TYPE_FLOAT === $min->type && \is_nan($min->toFloat())) {
            throw new \ValueError($function.'(): Argument #2 ($min) must not be NAN');
        }
        if (Variable::TYPE_FLOAT === $max->type && \is_nan($max->toFloat())) {
            throw new \ValueError($function.'(): Argument #3 ($max) must not be NAN');
        }
        if (Variable::spaceshipCompare($max, $min) < 0) {
            throw new \ValueError(
                $function.'(): Argument #2 ($min) must be smaller than or equal to argument #3 ($max)'
            );
        }
        if (Variable::spaceshipCompare($max, $value) < 0) {
            $result->copyFrom($max);

            return;
        }
        if (Variable::spaceshipCompare($value, $min) < 0) {
            $result->copyFrom($min);

            return;
        }
        $result->copyFrom($value);
    }
}
