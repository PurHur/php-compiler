<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Discarded pure-call elision for math / scalar / inet / hash builtins (#36387).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub is not one monolith
 * TU on the gen-0 spine (split-TU / size-budget ratchet). Shared arg predicates
 * ({@code mathArgAllowsDiscardedElision}, {@code stringArgAllowsDiscardedElision},
 * {@code scalarCastArgsAllowDiscardedElision}, {@code baseConvertArgsAllowDiscardedElision},
 * {@code inetArgsAllowDiscardedElision}, {@code minMaxArgsAllowDiscardedElision},
 * {@code checkdateArgsAllowDiscardedElision}, {@code clampArgsAllowDiscardedElision},
 * {@code intdivArgsAllowDiscardedElision}) stay on the hub class.
 *
 * Used via {@code use DiscardedPureCallElisionMathAndHashOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/standard/math.c, type.c, basic_functions.c,
 * datetime.c, array.c (min/max); ext/hash/hash.c.
 */
trait DiscardedPureCallElisionMathAndHashOps
{
    /**
     * Discarded {@code number_format} on already-numeric args (+ optional typed
     * decimals / nullable separators) — php-src {@code number_format.c}. Soft-null
     * num/decimals stay live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureNumberFormatNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureNumberFormatBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::numberFormatArgsAllowDiscardedElision($callArgs);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function numberFormatArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || !self::mathArgAllowsDiscardedElision($callArgs[0])
        ) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (
            !$callArgs[1] instanceof Variable
            || !self::mathArgAllowsDiscardedElision($callArgs[1])
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
                    self::stringArgAllowsDiscardedElision($callArgs[$i])
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
     * Discarded {@code intval}/{@code floatval}/{@code boolval}/{@code strval} on
     * typed scalars — php-src {@code type.c}/{@code basic_functions.c}. Objects
     * stay live ({@code __toString} / cast handlers); arrays stay live for
     * {@code strval} (array-to-string warning). Soft-null {@code intval} base
     * stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureScalarCastNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureScalarCastBuiltin($name)) {
            return false;
        }

        return self::scalarCastArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * Discarded {@code decbin}/{@code dechex}/{@code decoct} on typed numerics,
     * {@code bindec}/{@code hexdec}/{@code octdec} on typed / literal strings, and
     * {@code base_convert} with compile-time bases in [2,36] — php-src
     * {@code math.c}. Soft-null coerce deprecates so null stays live
     * (peer {@see tryElideChrNoSideEffect} / {@see tryElideStrlenNoSideEffect}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureBaseConvertNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureBaseConvertBuiltin($name)) {
            return false;
        }

        return self::baseConvertArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * Discarded {@code ip2long}/{@code inet_pton}/{@code inet_ntop} on typed /
     * literal strings and {@code long2ip} on typed numerics — php-src
     * {@code basic_functions.c}. Soft-null stays live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureInetNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureInetBuiltin($name)) {
            return false;
        }

        return self::inetArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * Discarded {@code min}/{@code max}/{@code fmin}/{@code fmax} on typed
     * numeric scalars — php-src {@code array.c} / {@code math.c}. Single-array
     * {@code min}/{@code max} and soft-null stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMinMaxNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureMinMaxBuiltin($name)) {
            return false;
        }

        return self::minMaxArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * Discarded {@code checkdate} on three typed numerics — php-src
     * {@code datetime.c}. Invalid dates return false (no throw). Soft-null
     * stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCheckdateNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureCheckdateBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::checkdateArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code clamp} when min/max are compile-time numerics that cannot
     * {@code ValueError} — php-src {@code ext/standard/math.c}
     * {@code PHP_FUNCTION(clamp)} / {@code php_math_clamp}. Runtime / inverted /
     * NAN bounds stay live (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureClampNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('clamp' !== strtolower($toCall->getName())) {
            return false;
        }

        return self::clampArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code intdiv} when both args are already-numeric and the divisor
     * is a compile-time long that cannot {@code DivisionByZeroError} /
     * {@code ArithmeticError} — php-src {@code ext/standard/math.c}
     * {@code PHP_FUNCTION(intdiv)}. Runtime / zero / {@code INT_MIN}/{-1} stay
     * live (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureIntdivNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('intdiv' !== strtolower($toCall->getName())) {
            return false;
        }

        return self::intdivArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code hash_equals} on two typed / literal strings — php-src
     * {@code hash.c}. Non-string / soft-null stay live ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureHashEqualsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureHashEqualsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::hashEqualsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code hash} — php-src {@code ext/hash/hash.c}. Compile-time
     * known algo ({@see \PHPCompiler\ext\standard\HashAlgosRegistry::ALL_ALGOS})
     * plus typed / literal data string and optional typed binary. Soft-null /
     * non-string stay live (deprecate / {@code TypeError}). Unknown / empty
     * algo stay live ({@code ValueError}). Options array form stays live
     * (seeded digests). Wrong arity stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureHashNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('hash' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 2 || $argc > 3) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || !self::compileTimeKnownHashAlgoAllowsDiscardedElision($callArgs[0], false)
        ) {
            return false;
        }
        if (
            !$callArgs[1] instanceof Variable
            || !self::stringArgAllowsDiscardedElision($callArgs[1])
        ) {
            return false;
        }
        if (3 === $argc) {
            if (
                !$callArgs[2] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[2])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code hash_hmac} — php-src {@code ext/hash/hash.c}. Compile-time
     * known HMAC algo ({@see \PHPCompiler\ext\standard\HashAlgosRegistry::HMAC_ALGOS})
     * plus typed / literal data and key strings and optional typed binary.
     * Soft-null / non-string stay live (deprecate / {@code TypeError}). Unknown
     * / empty / non-HMAC algo stay live ({@code ValueError}). Wrong arity stays
     * live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureHashHmacNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('hash_hmac' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 3 || $argc > 4) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || !self::compileTimeKnownHashAlgoAllowsDiscardedElision($callArgs[0], true)
        ) {
            return false;
        }
        if (
            !$callArgs[1] instanceof Variable
            || !self::stringArgAllowsDiscardedElision($callArgs[1])
        ) {
            return false;
        }
        if (
            !$callArgs[2] instanceof Variable
            || !self::stringArgAllowsDiscardedElision($callArgs[2])
        ) {
            return false;
        }
        if (4 === $argc) {
            if (
                !$callArgs[3] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[3])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compile-time non-empty algo string present in php-src hash / HMAC tables.
     * Runtime-typed string algos stay live ({@code ValueError} on unknown).
     */
    private static function compileTimeKnownHashAlgoAllowsDiscardedElision(
        Variable $arg,
        bool $hmacOnly
    ): bool {
        $algo = JitStringArg::compileTimeLiteral($arg);
        if (null === $algo || '' === $algo) {
            return false;
        }
        $lc = strtolower($algo);
        static $all = null;
        static $hmac = null;
        if (null === $all) {
            $all = [];
            foreach (\PHPCompiler\ext\standard\HashAlgosRegistry::ALL_ALGOS as $name) {
                $all[strtolower($name)] = true;
            }
            $hmac = [];
            foreach (\PHPCompiler\ext\standard\HashAlgosRegistry::HMAC_ALGOS as $name) {
                $hmac[strtolower($name)] = true;
            }
        }

        return $hmacOnly ? isset($hmac[$lc]) : isset($all[$lc]);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function hashEqualsArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[0])
            && self::stringArgAllowsDiscardedElision($callArgs[1]);
    }

}
