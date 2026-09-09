<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * No-throw / pure-builtin predicates for number_format, scalar casts,
 * base/inet/minmax/checkdate, hash_equals, pathinfo/parse_url, and pure
 * math builtins (#36387).
 *
 * Extracted from {@see NoThrowCallElision} so the hub stays under its
 * size-budget target. Shared arg helpers
 * ({@code stringParamBuiltinArgCannotThrow}, {@code numericParamBuiltinArgCannotThrow},
 * {@code intParamBuiltinArgCannotThrow}, {@code typedArrayArgCannotThrow})
 * live in {@see NoThrowCallElisionPureBuiltinArgOps}. Matching ArgsCannotThrow
 * peers for base/inet/minmax/… live in
 * {@see NoThrowCallElisionConvertAndScalarCastOps}.
 *
 * Used via {@code use NoThrowCallElisionMathAndFormatOps;} on
 * {@see NoThrowCallElision}.
 *
 * Public for {@see DiscardedPureCallElisionMathAndHashOps} /
 * {@see DiscardedPureCallElisionMathGuardAndVoidNativeOps}.
 *
 * No new C ABI. php-src: ext/standard/{math,string,type,basic_functions,
 * url,file}.c; ext/hash/hash.c; ext/date/php_date.c (checkdate).
 */
trait NoThrowCallElisionMathAndFormatOps
{

    /**
     * php-src {@code ext/standard/string.c} / {@code number_format.c} — formats a
     * numeric into a string. Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureNumberFormatBuiltin(string $nameLc): bool
    {
        return 'number_format' === $nameLc;
    }


    /**
     * php-src {@code ext/standard/type.c} / {@code basic_functions.c} scalar casts
     * that only read typed scalars (no {@code __toString} / array-to-string
     * warning). Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureScalarCastBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'intval':
            case 'floatval':
            case 'doubleval':
            case 'boolval':
            case 'strval':
                return true;
            default:
                return false;
        }
    }


    /**
     * php-src {@code ext/standard/math.c} base / radix converts — int→string
     * ({@code decbin}/{@code dechex}/{@code decoct}) or string→number
     * ({@code bindec}/{@code hexdec}/{@code octdec}) or {@code base_convert}.
     * Soft-null / object {@code __toString} stay out. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureBaseConvertBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'decbin':
            case 'dechex':
            case 'decoct':
            case 'bindec':
            case 'hexdec':
            case 'octdec':
            case 'base_convert':
                return true;
            default:
                return false;
        }
    }


    /**
     * php-src {@code ext/standard/basic_functions.c} {@code ip2long}/
     * {@code long2ip}/{@code inet_pton}/{@code inet_ntop} — typed string or
     * long; soft-null stays out. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureInetBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'ip2long':
            case 'long2ip':
            case 'inet_pton':
            case 'inet_ntop':
                return true;
            default:
                return false;
        }
    }


    /**
     * php-src {@code ext/standard/array.c} {@code min}/{@code max} and
     * {@code math.c} {@code fmin}/{@code fmax} on typed numeric scalars —
     * no user handlers. Single-array {@code min}/{@code max} stays out.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureMinMaxBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'min':
            case 'max':
            case 'fmin':
            case 'fmax':
                return true;
            default:
                return false;
        }
    }


    /**
     * php-src {@code ext/standard/datetime.c} {@code checkdate} — three longs;
     * invalid calendar dates return false (no throw). Soft-null deprecates.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureCheckdateBuiltin(string $nameLc): bool
    {
        return 'checkdate' === $nameLc;
    }


    /**
     * php-src {@code ext/hash/hash.c} {@code hash_equals} — two strings;
     * TypeError on non-string / soft-null stays out. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureHashEqualsBuiltin(string $nameLc): bool
    {
        return 'hash_equals' === $nameLc;
    }


    /**
     * php-src {@code ext/standard/basic_functions.c} / {@code file.c}
     * {@code pathinfo} — Z_PARAM_STR path + optional Z_PARAM_LONG flags.
     * Soft-null path/flags deprecate. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPurePathinfoBuiltin(string $nameLc): bool
    {
        return 'pathinfo' === $nameLc;
    }


    /**
     * php-src {@code ext/standard/url.c} {@code parse_url} — Z_PARAM_STR url +
     * optional Z_PARAM_LONG component. Soft-null url/component deprecate.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureParseUrlBuiltin(string $nameLc): bool
    {
        return 'parse_url' === $nameLc;
    }


    /**
     * {@code number_format} — numeric num [, long decimals [, string|null sep…]].
     *
     * @param array<int, Variable> $callArgs
     */
    public static function numberFormatArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || !self::numericParamBuiltinArgCannotThrow($callArgs[0])
        ) {
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
        for ($i = 2; $i <= 3; ++$i) {
            if (!isset($callArgs[$i])) {
                return true;
            }
            if (
                !$callArgs[$i] instanceof Variable
                || !(
                    self::stringParamBuiltinArgCannotThrow($callArgs[$i])
                    || Variable::TYPE_NULL === $callArgs[$i]->type
                    || $callArgs[$i]->isNullConstant
                )
            ) {
                return false;
            }
        }

        return !isset($callArgs[4]);
    }


    /**
     * php-src {@code ext/standard/math.c} builtins that only coerce a numeric
     * scalar and never invoke user handlers (no {@code __toString} on object /
     * value-box paths we already exclude via {@see numericParamBuiltinArgCannotThrow}).
     *
     * Public for {@see DiscardedPureCallElision} — discarded statements of these
     * builtins are side-effect-free when args are already numeric (#36386).
     */
    public static function isPureMathBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'sqrt':
            case 'abs':
            case 'floor':
            case 'ceil':
            case 'round':
            case 'sin':
            case 'cos':
            case 'tan':
            case 'asin':
            case 'acos':
            case 'atan':
            case 'sinh':
            case 'cosh':
            case 'tanh':
            case 'asinh':
            case 'acosh':
            case 'atanh':
            case 'exp':
            case 'expm1':
            case 'log':
            case 'log10':
            case 'log1p':
            case 'hypot':
            case 'fmod':
            case 'atan2':
            case 'deg2rad':
            case 'rad2deg':
            // math.c nextafter — IEEE next float; no user handlers / no ValueError
            // on typed numeric args (peer hypot / fmod; #36386).
            case 'nextafter':
            // math.c pow / fpow / fdiv — no user handlers; domain errors are
            // NAN/INF (fdiv ÷0 → INF). intdiv is handled separately via
            // {@see DiscardedPureCallElision::intdivArgsCannotThrow} (DivisionByZeroError /
            // ArithmeticError unless divisor is compile-time-safe).
            case 'pow':
            case 'fpow':
            case 'fdiv':
            // math.c pi() — zero-arg constant (M_PI); no user handlers.
            case 'pi':
                return true;
            default:
                return false;
        }
    }
}
