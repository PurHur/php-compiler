<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/** Shared math coercion helpers for ext/standard (issue #3578) and base_convert (#3173). */
final class VmMath
{
    private const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

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
     * Z_PARAM_BOOL-style coercion for bool-only builtins (php-src basic_functions.c; #6149 microtime/hrtime).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
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
     * Z_PARAM_NUMBER-style coercion for int|float builtins (php-src math.c abs/ceil/floor/round; #5613).
     *
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function parseNumberBuiltinArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
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
            throw new \TypeError(self::nullableIntBuiltinTypeError($function, $argIndex, $paramName, 'object'));
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
            throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'object'));
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
            throw new \TypeError(self::intBuiltinTypeError($function, $argIndex, $paramName, 'object'));
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
     * pow() return typing — int when both operands are int with integral result (php-src math.c, issue #3678).
     */
    public static function applyPow(Variable $returnVar, Variable $base, Variable $exp): void
    {
        $returnVar->reset();
        $baseNum = self::parseNumberBuiltinArg($base, 'pow', 1, 'num');
        $expNum = self::parseNumberBuiltinArg($exp, 'pow', 2, 'exponent');
        if (\is_int($baseNum) && \is_int($expNum)) {
            $result = $baseNum ** $expNum;
            if (\is_int($result)) {
                $returnVar->int($result);

                return;
            }
            $returnVar->float($result);

            return;
        }
        $returnVar->float(\pow((float) $baseNum, (float) $expNum));
    }

    /**
     * fpow() — always returns float (php-src ext/standard/math.c zend_fpow; #7045).
     */
    public static function fpow(float $num, float $exponent): float
    {
        return \pow($num, $exponent);
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
     * JIT/AOT lower via {@see MathBaseConvertJit} (no phpc_base_convert.c, #5197).
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
     */
    private static function baseToZvalFloat(string $str, int $start, int $end, int $base): float
    {
        $fnum = 0.0;

        for ($i = $start; $i < $end; ++$i) {
            $digit = self::radixDigit($str[$i], $base);
            if (null === $digit) {
                continue;
            }
            $fnum = $fnum * $base + $digit;
        }

        return $fnum;
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
}
