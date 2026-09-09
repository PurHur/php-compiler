<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Convert / path-url / hash_equals / scalar-cast no-throw arg proofs for
 * {@see NoThrowCallElision} (#36387).
 *
 * Extracted from {@see NoThrowCallElisionExistsConvertAndIntrospectOps} so that
 * TU stays under its size-budget ceiling and gen-0 spine gets another unit.
 * Name predicates for these builtins live in
 * {@see NoThrowCallElisionMathAndFormatOps}. External call sites keep using
 * {@code NoThrowCallElision::…} (trait methods on the hub).
 *
 * Used via {@code use NoThrowCallElisionConvertAndScalarCastOps;} on
 * {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: ext/standard/{math,type,string,url,file}.c;
 * ext/hash/hash.c; ext/date/php_date.c (checkdate).
 */
trait NoThrowCallElisionConvertAndScalarCastOps
{
    /**
     * @param array<int, Variable> $callArgs
     */
    public static function baseConvertArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'decbin':
            case 'dechex':
            case 'decoct':
                // Z_PARAM_LONG — soft-null deprecates (stay live).
                return !isset($callArgs[1])
                    && self::numericParamBuiltinArgCannotThrow($callArgs[0]);
            case 'bindec':
            case 'hexdec':
            case 'octdec':
                // Z_PARAM_STR — soft-null / __toString stay live.
                return !isset($callArgs[1])
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0]);
            case 'base_convert':
                // string, long from_base, long to_base — ValueError when bases
                // outside [2,36]; only compile-time bases in range prove.
                if (
                    !isset($callArgs[1], $callArgs[2])
                    || isset($callArgs[3])
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }

                return self::compileTimeRadixBaseInRange($callArgs[1])
                    && self::compileTimeRadixBaseInRange($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function inetArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[1])) {
            return false;
        }
        switch ($nameLc) {
            case 'ip2long':
            case 'inet_pton':
            case 'inet_ntop':
                // Z_PARAM_STR — soft-null / __toString stay live.
                return self::stringParamBuiltinArgCannotThrow($callArgs[0]);
            case 'long2ip':
                // Z_PARAM_LONG — soft-null deprecates (stay live).
                return self::numericParamBuiltinArgCannotThrow($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Typed numeric scalars only (≥1 for min/max, ≥2 for fmin/fmax). Array-form
     * {@code min}/{@code max} (single hashtable / native array) stays out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function minMaxArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        if (('fmin' === $nameLc || 'fmax' === $nameLc) && \count($callArgs) < 2) {
            return false;
        }
        // Single array argument → php_min_max over elements (object handlers).
        if (1 === \count($callArgs) && self::typedArrayArgCannotThrow($callArgs[0])) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::numericParamBuiltinArgCannotThrow($arg)) {
                return false;
            }
            // Soft-null numeric params deprecate — stay conservative for no-throw
            // (peer math discarded elision excludes TYPE_NULL).
            if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exactly three typed numeric args — soft-null / value-box stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function checkdateArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1], $callArgs[2])
            || isset($callArgs[3])
        ) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::numericParamBuiltinArgCannotThrow($arg)) {
                return false;
            }
            if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exactly two typed / literal strings — TypeError / soft-null stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function hashEqualsArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }

        return self::stringParamBuiltinArgCannotThrow($callArgs[0])
            && self::stringParamBuiltinArgCannotThrow($callArgs[1]);
    }

    /**
     * Typed / literal string path + optional typed numeric flags — soft-null
     * path/flags stay out (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function pathinfoArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
        ) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (
            !$callArgs[1] instanceof Variable
            || !self::numericParamBuiltinArgCannotThrow($callArgs[1])
            || $callArgs[1]->isNullConstant
            || Variable::TYPE_NULL === $callArgs[1]->type
            || isset($callArgs[2])
        ) {
            return false;
        }

        return true;
    }

    /**
     * Typed / literal string url + optional typed numeric component — soft-null
     * url/component stay out (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function parseUrlArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
        ) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (
            !$callArgs[1] instanceof Variable
            || !self::numericParamBuiltinArgCannotThrow($callArgs[1])
            || $callArgs[1]->isNullConstant
            || Variable::TYPE_NULL === $callArgs[1]->type
            || isset($callArgs[2])
        ) {
            return false;
        }

        return true;
    }

    /** Compile-time long in [2, 36] — {@code base_convert} radix (math.c). */
    public static function compileTimeRadixBaseInRange(Variable $arg): bool
    {
        return null !== $arg->compileTimeLong
            && $arg->compileTimeLong >= 2
            && $arg->compileTimeLong <= 36;
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function scalarCastArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'intval':
                // value [, long base] — soft-null base deprecates (stay live).
                if (!self::scalarCastValueArgCannotThrow($callArgs[0], true)) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }
                if (
                    !$callArgs[1] instanceof Variable
                    || !self::numericParamBuiltinArgCannotThrow($callArgs[1])
                ) {
                    return false;
                }

                return !isset($callArgs[2]);
            case 'floatval':
            case 'doubleval':
            case 'boolval':
                // Single scalar (boolval also accepts typed arrays — no user handler).
                if (isset($callArgs[1])) {
                    return false;
                }
                if ('boolval' === $nameLc && self::typedArrayArgCannotThrow($callArgs[0])) {
                    return true;
                }

                return self::scalarCastValueArgCannotThrow($callArgs[0], true);
            case 'strval':
                // Objects invoke __toString; arrays warn — typed scalars / null only.
                return !isset($callArgs[1])
                    && self::scalarCastValueArgCannotThrow($callArgs[0], true);
            default:
                return false;
        }
    }

    /**
     * Typed string / numeric / bool / null — no object / value-box / hashtable.
     */
    private static function scalarCastValueArgCannotThrow(Variable $arg, bool $allowNull): bool
    {
        if ($allowNull && ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type)) {
            return true;
        }
        if (self::stringParamBuiltinArgCannotThrow($arg)) {
            return true;
        }

        return self::numericParamBuiltinArgCannotThrow($arg);
    }

}
