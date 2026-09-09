<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\ext\standard\VmString;

/**
 * Discarded pure-call elision for type predicates / ctype / strlen / ord / chr /
 * pure string-transform / str_increment|decrement (#36387 / #23483).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub is not one monolith
 * TU on the gen-0 spine (split-TU / size-budget ratchet). Shared arg predicates
 * ({@code stringArgAllowsDiscardedElision}, {@code mathArgAllowsDiscardedElision})
 * stay on the hub class.
 *
 * Used via {@code use DiscardedPureCallElisionTypeStringCoreOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/standard/type.c, basic_functions.c (gettype);
 * ext/ctype/ctype.c; ext/standard/string.c (strlen/ord/chr/strtolower/…/
 * str_increment/str_decrement), url.c, md5.c, crc32.c, base64.c, quot_print.c,
 * basename.c, file.c.
 */
trait DiscardedPureCallElisionTypeStringCoreOps
{
    /**
     * Discarded {@code is_int}/{@code is_string}/…/{@code gettype} — php-src
     * {@code type.c} / {@code basic_functions.c} only read the zval type tag
     * (peer {@see NoThrowCallElision}).
     */
    private static function tryElidePureTypePredicate(?Call $toCall): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }

        return NoThrowCallElision::isPureTypePredicateBuiltin(strtolower($toCall->getName()));
    }

    /**
     * Discarded {@code ctype_*} on a typed / literal string — php-src
     * {@code ext/ctype/ctype.c} only reads bytes when the arg is already a
     * string. Int / null still emit ctype_fallback deprecations (#19717 /
     * #20611) so those stay live (peer {@see tryElideStrlenNoSideEffect}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCtypeNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureCtypeBuiltin(strtolower($toCall->getName()))) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideStrlenNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('strlen' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        $arg = $callArgs[0];
        // Literal or already-a-string slot — no Z_PARAM_STR coercion / deprecate.
        if (null !== JitStringArg::compileTimeLiteral($arg)) {
            return true;
        }

        return Variable::TYPE_STRING === $arg->type;
    }

    /**
     * Discarded {@code ord()} on a typed / literal string — php-src
     * {@code string.c} {@code PHP_FUNCTION(ord)} only reads the first byte;
     * soft int→string / null coerce deprecates (PHP 8.1+) so those stay live
     * (peer {@see tryElideStrlenNoSideEffect}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideOrdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('ord' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        $arg = $callArgs[0];
        if (null !== JitStringArg::compileTimeLiteral($arg)) {
            return true;
        }

        return Variable::TYPE_STRING === $arg->type;
    }

    /**
     * Discarded {@code chr()} on already-numeric args — php-src
     * {@code string.c} {@code PHP_FUNCTION(chr)} is Z_PARAM_LONG; null soft
     * coerce deprecates so TYPE_NULL is excluded (peer math discarded elision).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideChrNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('chr' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }

        return self::mathArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * Discarded {@code strtolower}/{@code ucwords}/{@code bin2hex}/
     * {@code urlencode}/{@code str_rot13}/{@code quotemeta}/{@code md5}/
     * {@code crc32}/{@code base64_encode}/{@code soundex}/
     * {@code addcslashes}/{@code stripcslashes}/
     * {@code quoted_printable_*}/{@code basename}/{@code dirname}/… on typed /
     * literal strings (+ optional typed numeric/bool trailing args) — php-src
     * {@code string.c}/{@code url.c}/{@code md5.c}/{@code crc32.c}/
     * {@code base64.c}/{@code quot_print.c}/{@code basename.c}/{@code file.c}
     * Z_PARAM_STR family; soft null / object {@code __toString} stay live
     * (peer {@see tryElideStrlenNoSideEffect}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStringTransformNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureStringTransformBuiltin($name)) {
            return false;
        }

        return self::stringTransformArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function stringTransformArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'md5':
            case 'sha1':
            case 'metaphone':
            case 'hebrev':
            case 'hebrevc':
                // string [, long|bool trailing] — binary / phonemes / max_chars.
                if (
                    !isset($callArgs[0])
                    || !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
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

                return !isset($callArgs[2]);
            case 'dirname':
                // string [, long levels≥1] — ValueError when levels < 1 (php-src
                // basename.c / file.c peer). Unknown typed ints stay live.
                if (
                    !isset($callArgs[0])
                    || !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                ) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }
                if (!$callArgs[1] instanceof Variable || isset($callArgs[2])) {
                    return false;
                }

                return null !== $callArgs[1]->compileTimeLong
                    && $callArgs[1]->compileTimeLong >= 1;
            case 'basename':
                // string [, string suffix]
                if (
                    !isset($callArgs[0])
                    || !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                ) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[1])
                    && !isset($callArgs[2]);
            case 'quoted_printable_encode':
            case 'quoted_printable_decode':
                // single Z_PARAM_STR
                return isset($callArgs[0])
                    && $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && !isset($callArgs[1]);
            default:
                // strtolower / trim / urlencode / addcslashes / … — all string slots.
                foreach ($callArgs as $arg) {
                    if (!$arg instanceof Variable || !self::stringArgAllowsDiscardedElision($arg)) {
                        return false;
                    }
                }

                return true;
        }
    }


    /**
     * Discarded {@code str_increment}/{@code str_decrement} when the single arg
     * is a compile-time ASCII-alphanumeric string that cannot
     * {@code ValueError} — php-src {@code ext/standard/string.c}
     * {@code PHP_FUNCTION(str_increment)} / {@code PHP_FUNCTION(str_decrement)}.
     * Soft-null / runtime typed strings / empty / non-alphanumeric / leading
     * {@code '0'} or single-char {@code a}/{@code A} decrement stay live
     * (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStrIncDecNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }

        return self::strIncDecArgsCannotThrow(strtolower($toCall->getName()), $callArgs);
    }

    /**
     * Public for {@see NoThrowCallElision} and {@code str_increment}/
     * {@code str_decrement} compile-time fold — when true, the call cannot
     * {@code ValueError} / soft-null-deprecate (#36386 / peer #37168).
     * Uses {@see JitStringArg::compileTimeLiteral} (same as discarded elision):
     * call-arg temps for source literals are often {@code KIND_VARIABLE} with
     * {@code compileTimeString} set; {@see JitStringArg::compileTimeLiteralForFold}
     * would reject those and miss the hot path.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function strIncDecArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if ('str_increment' !== $nameLc && 'str_decrement' !== $nameLc) {
            return false;
        }
        if (!CompilerVersion::supportsStrIncrement()) {
            return false;
        }
        if (1 !== \count($callArgs) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        $lit = JitStringArg::compileTimeLiteral($callArgs[0]);
        if (null === $lit) {
            return false;
        }
        if ('str_increment' === $nameLc) {
            return self::compileTimeStrIncrementAllowsDiscardedElision($lit);
        }

        return self::compileTimeStrDecrementAllowsDiscardedElision($lit);
    }

    /**
     * php-src {@code str_increment} ValueError gates — empty / non-alphanumeric.
     */
    private static function compileTimeStrIncrementAllowsDiscardedElision(string $literal): bool
    {
        return '' !== $literal && VmString::onlyAsciiAlphanumeric($literal);
    }

    /**
     * php-src {@code str_decrement} ValueError gates — empty / non-alphanumeric /
     * leading {@code '0'} / single-char {@code a}/{@code A} (out of range).
     */
    private static function compileTimeStrDecrementAllowsDiscardedElision(string $literal): bool
    {
        if ('' === $literal || !VmString::onlyAsciiAlphanumeric($literal)) {
            return false;
        }
        if ('0' === $literal[0]) {
            return false;
        }
        // Single-char a/A underflows the alphabet (php-src string.c).
        if (1 === \strlen($literal) && ('a' === $literal || 'A' === $literal)) {
            return false;
        }

        return true;
    }
}
