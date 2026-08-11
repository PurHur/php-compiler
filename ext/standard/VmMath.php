<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
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

    /** php-src math.c — _php_math_basetozval invalid-digit E_DEPRECATED (#24950). */
    public const INVALID_RADIX_CHARS_MESSAGE =
        'Invalid characters passed for attempted conversion, these have been ignored';

    /** Set by {@see baseToZval()}; consumed by VM/JIT callers (#24950). */
    private static bool $invalidRadixChars = false;

    /**
     * Historically gated Z_PARAM_LONG null→TypeError on PROFILE=8.4 (#18850), but Zend 8.4 still
     * deprecates and coerces null→0 for Z_PARAM_LONG (php-src zend_API.h / math.c). Keep false until
     * a real PHP 9 gate exists (#21593 intdiv, #21594 checkdate peers).
     *
     * Call sites that always soft-null use {@see parseChrCodepoint} / {@see parseZParamLongBuiltinArg}.
     */
    /**
     * Reject null for a non-nullable int builtin parameter (Z_PARAM_LONG without NULLABLE;
     * php-src html.c $flags — #24696). Call before parseIntBuiltinArg for parameters where
     * Zend never accepts null, regardless of forward-profile or strict_types.
     */
    public static function rejectNullIntBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        if (Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'null'));
        }
    }

    public static function requiresForwardProfileStrictLongNull(): bool
    {
        return false;
    }

    /**
     * Z_PARAM_DOUBLE null rejection on PHP 8.4 forward profile (fadd/fsub/fmul only; #19182).
     * fpow/sqrt use soft-null outside strict_types (#24177); under strict_types → TypeError (#30021 / #29782).
     * pow() uses operator path — silent null (#29322).
     */
    public static function requiresForwardProfileStrictDoubleNull(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
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

    /**
     * php-src zend_operators.c — float→long precision loss (issue #10440, #27926).
     * Non-finite values also emit E_DEPRECATED (message still says “loses precision”).
     */
    public static function floatLosesIntPrecision(float $value): bool
    {
        if (!\is_finite($value)) {
            return true;
        }

        return $value !== (float) self::floatToZendLong($value);
    }

    public static function floatToIntPrecisionWarningMessage(float $value): string
    {
        // Cast (not %g): INF/NAN/-INF must match Zend’s uppercase spellings (#27926).
        return \sprintf('Implicit conversion from float %s to int loses precision', (string) $value);
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

    /**
     * Strict bool param — null is TypeError (php-src Z_PARAM_BOOL on non-nullable typed params).
     */
    public static function parseBoolBuiltinArgStrict(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(self::boolBuiltinTypeError($function, $argIndex, $paramName, 'null'));
        }

        return self::parseBoolBuiltinArg($var, $function, $argIndex, $paramName);
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
                // Z_PARAM_BOOL — null→false with E_DEPRECATED (php-src zend_API.h; #21702).
                VmNullNumberParamDeprecation::emit(null, $function, $argIndex, $paramName, 'bool');

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
            // Z_PARAM_NUMBER + caller strict_types → TypeError (php-src zend_verify_arg_type; #4189).
            if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(self::numberBuiltinTypeError($function, $argIndex, $paramName, 'bool'));
            }

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
            // Numeric strings coerce only when the caller is not strict_types (#4189).
            if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(self::numberBuiltinTypeError($function, $argIndex, $paramName, 'string'));
            }
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
        string $paramName,
        ?Frame $frame = null
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

        return self::parseLongBuiltinArgCore($var, $function, $argIndex, $paramName, $frame);
    }

    /**
     * Z_PARAM_LONG coercion where null becomes 0 when the caller is not strict_types
     * (ext/random/random.c random_bytes without strict_types; #19054).
     *
     * Prefer {@see parseZParamLongBuiltinArgForFrame} so `declare(strict_types=1)` matches
     * Zend TypeError (#19230, same as sleep #19079).
     */
    public static function parseZParamLongBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName,
        ?Frame $frame = null
    ): int {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            VmNullNumberParamDeprecation::emit($frame, $function, $argIndex, $paramName, 'int');

            return 0;
        }

        return self::parseIntBuiltinArg($var, $function, $argIndex, $paramName);
    }

    /**
     * Z_PARAM_LONG builtin args — null coerces to 0 without caller strict_types (php-src basic_functions.c
     * sleep/usleep/time_nanosleep; #19077). With strict_types, null/non-int operands TypeError like chr() (#19079).
     */
    public static function parseZParamLongBuiltinArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName
    ): int {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return self::parseIntBuiltinArgForFrame($frame, $argIndex, $function, $userArgIndex, $paramName);
        }
        $var = $frame->calledArgs[$argIndex];
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_FLOAT === $resolved->type && null !== $frame->vmContext) {
            self::warnFloatToIntPrecisionLoss($resolved->toFloat(), $frame->vmContext, $frame);
        }

        return self::parseZParamLongBuiltinArg($var, $function, $userArgIndex, $paramName, $frame);
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
            // Use $userArgIndex for TypeError — method frames include $this (#29829).
            $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $arg->type) {
                throw new \TypeError(
                    self::intBuiltinTypeError(
                        $function,
                        $userArgIndex,
                        $paramName,
                        EnumCaseSupport::typeNameForTypeErrorActual($arg)
                    )
                );
            }

            return $arg->toInt();
        }
        $var = $frame->calledArgs[$argIndex];
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_FLOAT === $resolved->type && null !== $frame->vmContext) {
            self::warnFloatToIntPrecisionLoss($resolved->toFloat(), $frame->vmContext, $frame);
        }

        return self::parseIntBuiltinArg($var, $function, $userArgIndex, $paramName, $frame);
    }

    /**
     * chr() codepoint with caller strict_types parity (php-src string.c php_chr; #5085, #21222).
     */
    public static function parseChrCodepointForFrame(
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

        return self::parseChrCodepoint($var, $function, $userArgIndex, $paramName, $frame);
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
        string $paramName,
        ?Frame $frame = null
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
            // Z_PARAM_LONG / zend_dval_to_lval_safe: INF/NAN → TypeError (#27925).
            if (!\is_finite($f)) {
                throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'float'));
            }

            return self::floatToZendLong($f);
        }
        if (Variable::TYPE_NULL === $var->type) {
            // Z_PARAM_LONG: E_DEPRECATED then coerce to 0 on forward profile (#19756, #21222).
            VmNullNumberParamDeprecation::emit($frame, $function, $argIndex, $paramName, 'int');

            return 0;
        }

        return self::parseLongBuiltinArgCore($var, $function, $argIndex, $paramName, $frame);
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
        string $paramName,
        ?Frame $frame = null
    ): int {
        switch ($var->type) {
            case Variable::TYPE_INTEGER:
                return $var->toInt();
            case Variable::TYPE_BOOLEAN:
                return $var->toBool() ? 1 : 0;
            case Variable::TYPE_NULL:
                if (self::requiresForwardProfileStrictLongNull()) {
                    throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'null'));
                }
                // Z_PARAM_LONG: E_DEPRECATED then coerce to 0 (chr/dechex; #19756).
                VmNullNumberParamDeprecation::emit($frame, $function, $argIndex, $paramName, 'int');

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
        string $paramName,
        ?Frame $frame = null
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
                // Z_PARAM_DOUBLE: E_DEPRECATED then coerce to 0.0 (sqrt/sin/log; #19756, #20432).
                VmNullNumberParamDeprecation::emit($frame, $function, $argIndex, $paramName, 'float');

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
     * Z_PARAM_DOUBLE null → TypeError on PHP 8.4 forward profile
     * (fadd/fsub/fmul and nextafter $toward only; #19182, #20432).
     * fdiv/fmod/hypot/atan2/fpow use {@see parseStrictFloatBuiltinArgForFrame} (strict TypeError;
     * else soft-null via {@see parseDoubleBuiltinArg}) (#29319, #30021).
     *
     * @throws \TypeError when operand is null on PROFILE=8.4+
     */
    public static function parseForwardProfileStrictDoubleBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName,
        ?Frame $frame = null
    ): float {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type && self::requiresForwardProfileStrictDoubleNull()) {
            throw new \TypeError(self::doubleBuiltinTypeError($function, $argIndex, $paramName, 'null'));
        }

        return self::parseDoubleBuiltinArg($var, $function, $argIndex, $paramName, $frame);
    }

    /**
     * float builtin args with caller strict_types TypeError on null/string/bool
     * (#11497, #29782, ext/standard/math.c Z_PARAM_DOUBLE + zend_verify_arg_type).
     * Soft path (no strict_types): E_DEPRECATED + coerce via {@see parseDoubleBuiltinArg}.
     *
     * @param int $userArgIndex 1-based parameter index (matches Zend Argument #N)
     */
    public static function parseStrictFloatBuiltinArgForFrame(
        Frame $frame,
        string $function,
        int $userArgIndex,
        string $paramName
    ): float {
        $slot = $userArgIndex - 1;
        if (InternalStrictArg::isCallerStrict($frame)) {
            $arg = InternalStrictArg::requireFloat($frame, $slot, $function, $paramName);
            if (Variable::TYPE_INTEGER === $arg->type) {
                return (float) $arg->toInt();
            }

            return $arg->toFloat();
        }

        return self::parseDoubleBuiltinArg(
            $frame->calledArgs[$slot],
            $function,
            $userArgIndex,
            $paramName,
            $frame
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

    /**
     * php-src convert_to_boolean / zend_is_true for IS_STRING (Z_PARAM_BOOL).
     * Empty string and exact "0" are false; every other string is true (#4293).
     */
    private static function coerceBoolStringLiteral(
        string $literal,
        string $function,
        int $argIndex,
        string $paramName
    ): bool {
        return '' !== $literal && '0' !== $literal;
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

    /**
     * log($num, $base) — logarithm with explicit base (php-src ext/standard/math.c PHP_FUNCTION(log)).
     *
     * Order matches php-src: base 2 / 10 fast paths, base == 1.0 → NAN, base ≤ 0 → ValueError.
     */
    public static function logWithBase(float $num, float $base): float
    {
        if (2.0 === $base) {
            return \log($num) / \M_LN2;
        }
        if (10.0 === $base) {
            return \log10($num);
        }
        if (1.0 === $base) {
            return \NAN;
        }
        if ($base <= 0.0) {
            throw new \ValueError('log(): Argument #2 ($base) must be greater than 0');
        }

        return \log($num) / \log($base);
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

    /** Internal fractional/integer split (C modf); not a userland builtin (#25359). */
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
        // Leave {@see $invalidRadixChars} for the caller (VM execute / JIT helper) (#24950).

        return is_float($value)
            ? self::doubleToBase($value, $toBase)
            : self::longToBase((int) $value, $toBase);
    }

    /** Whether the last {@see baseToZval()} skipped invalid digits (php-src E_DEPRECATED; #24950). */
    public static function takeInvalidRadixCharsDeprecation(): bool
    {
        $seen = self::$invalidRadixChars;
        self::$invalidRadixChars = false;

        return $seen;
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
        self::$invalidRadixChars = false;
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
                self::$invalidRadixChars = true;
            }

            return self::baseToZvalFloat($str, $start, $end, $base);
        }

        if ($invalidChars > 0) {
            self::$invalidRadixChars = true;
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
        if (self::takeInvalidRadixCharsDeprecation()) {
            VmMathRadixDeprecation::emit();
        }
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

    /**
     * C strtol() semantics: stops at first non-digit character, no 0o prefix.
     *
     * php-src: ZEND_STRTOL → strtol(3) — used by intval() runtime path.
     * Unlike baseToZval() this does NOT skip invalid characters.
     */
    public static function zendStrtol(string $str, int $base): int
    {
        $len = \strlen($str);
        $i = 0;

        while ($i < $len && \ctype_space($str[$i])) {
            ++$i;
        }

        $negative = false;
        if ($i < $len && ('+' === $str[$i] || '-' === $str[$i])) {
            $negative = '-' === $str[$i];
            ++$i;
        }

        if (0 === $base) {
            if ($i < $len && '0' === $str[$i]) {
                if ($i + 1 < $len && ('x' === $str[$i + 1] || 'X' === $str[$i + 1])) {
                    $base = 16;
                    $i += 2;
                } elseif ($i + 1 < $len && ('b' === $str[$i + 1] || 'B' === $str[$i + 1])) {
                    $base = 2;
                    $i += 2;
                } else {
                    $base = 8;
                }
            } else {
                $base = 10;
            }
        } elseif ($i + 1 < $len && '0' === $str[$i]) {
            if (16 === $base && ('x' === $str[$i + 1] || 'X' === $str[$i + 1])) {
                $i += 2;
            } elseif (2 === $base && ('b' === $str[$i + 1] || 'B' === $str[$i + 1])) {
                $i += 2;
            }
        }

        $num = 0;
        $parsed = false;
        for (; $i < $len; ++$i) {
            $digit = self::radixDigit($str[$i], $base);
            if (null === $digit) {
                break;
            }
            $parsed = true;
            $num = $num * $base + $digit;
        }

        return $negative ? -$num : $num;
    }
}
