<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Slice/compare / pad/split / replace/join no-throw proofs for
 * {@see NoThrowCallElision} (#36387).
 *
 * Extracted from {@see NoThrowCallElisionStringOps} so that TU stays under
 * its size-budget ceiling and gen-0 spine gets another unit. External call
 * sites keep using {@code NoThrowCallElision::…} (trait methods on the hub).
 *
 * Used via {@code use NoThrowCallElisionStringSlicePadReplaceOps;} on
 * {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: ext/standard/{string,levenshtein,file}.c
 * (substr/strpos/str_pad/explode/str_replace/str_getcsv and peers).
 */
trait NoThrowCallElisionStringSlicePadReplaceOps
{
    /**
     * php-src {@code ext/standard/string.c} slice / compare / search builtins
     * that only read string (and optional numeric) args — no user handlers when
     * every string slot is already a string and every numeric slot is already
     * numeric. Int needles for {@code strpos}/{@code strchr}/… stay out (PHP 8
     * deprecations). Public for {@see DiscardedPureCallElision}.
     */
    public static function isPureStringSliceOrCompareBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'substr':
            case 'str_repeat':
            case 'strcmp':
            case 'strcasecmp':
            case 'strnatcmp':
            case 'strnatcasecmp':
            case 'strncmp':
            case 'strncasecmp':
            case 'strpos':
            case 'stripos':
            case 'strrpos':
            case 'strripos':
            case 'strstr':
            case 'stristr':
            case 'strchr':
            case 'strrchr':
            case 'strpbrk':
            case 'strcspn':
            case 'strspn':
            case 'substr_count':
            // PHP 8.0+ string.c — haystack + needle strings only.
            case 'str_contains':
            case 'str_starts_with':
            case 'str_ends_with':
            // levenshtein.c — two strings + optional insertion/replacement/deletion costs.
            case 'levenshtein':
            // string.c similar_text — two strings only; &$percent form stays out.
            case 'similar_text':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/string.c} pad / split / wrap builtins that only
     * read typed string (+ optional numeric / pad string) args — no user handlers
     * when every string slot is already a string. Domain ValueErrors (empty
     * explode separator, non-positive chunk length, …) mirror {@code str_repeat}
     * discarded elision: typed numeric/string slots stay elidable (#36386).
     * Public for {@see DiscardedPureCallElision}.
     */
    public static function isPureStringPadOrSplitBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'str_pad':
            case 'chunk_split':
            case 'wordwrap':
            case 'str_split':
            case 'explode':
            // file.c str_getcsv — four typed strings only; omitted $escape DEP stays live.
            case 'str_getcsv':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/string.c} replace builtins that only read typed
     * string (+ optional numeric) args — no user handlers and no by-ref count
     * write. Array subject/search/replace and {@code strtr} replace_pairs stay
     * out (element {@code __toString} / empty-replacement warnings). Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureStringReplaceOrJoinBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'str_replace':
            case 'str_ireplace':
            case 'substr_replace':
            case 'strtr':
                return true;
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function stringReplaceOrJoinArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'str_replace':
            case 'str_ireplace':
                // search, replace, subject — strings only; &$count is a write.
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[2]);
            case 'substr_replace':
                // string, replace, long offset [, long length] — string subject only.
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[1])
                    || !$callArgs[2] instanceof Variable
                    || !self::numericParamBuiltinArgCannotThrow($callArgs[2])
                ) {
                    return false;
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[3]);
            case 'strtr':
                // Three-string form only (from/to spans). Two-arg replace_pairs
                // may warn on empty replacements and stringify pair values.
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function stringPadOrSplitArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'str_pad':
                // string, long length [, string pad_string [, long pad_type]]
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::numericParamBuiltinArgCannotThrow($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }
                if (
                    !$callArgs[2] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[2])
                ) {
                    return false;
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[3]);
            case 'chunk_split':
                // string [, long length [, string separator]]
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
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[2]);
            case 'wordwrap':
                // string [, long width [, string break [, bool cut]]]
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
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }
                if (
                    !$callArgs[2] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[2])
                ) {
                    return false;
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[3]);
            case 'str_split':
                // string [, long length]
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

                return $callArgs[1] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[1]);
            case 'explode':
                // string separator, string string [, long limit]
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[2]);
            case 'str_getcsv':
                // string [, separator, enclosure, escape] — omitted $escape emits
                // E_DEPRECATED (php-src 8.4+ file.c); require all four typed strings.
                if (
                    !isset($callArgs[0], $callArgs[1], $callArgs[2], $callArgs[3])
                    || isset($callArgs[4])
                ) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[2])
                    && $callArgs[3] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[3]);
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function stringSliceOrCompareArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'substr':
                // string, long offset [, long length]
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                ) {
                    return false;
                }
                if (
                    !$callArgs[1] instanceof Variable
                    || !self::numericParamBuiltinArgCannotThrow($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[2]);
            case 'str_repeat':
                // string, long times
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[1]);
            case 'levenshtein':
                // string, string [, long insert [, long replace [, long delete]]]
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[1])
                ) {
                    return false;
                }
                for ($i = 2, $n = count($callArgs); $i < $n; ++$i) {
                    if ($i > 4) {
                        return false;
                    }
                    if (
                        !$callArgs[$i] instanceof Variable
                        || !self::numericParamBuiltinArgCannotThrow($callArgs[$i])
                    ) {
                        return false;
                    }
                }

                return true;
            case 'similar_text':
                // Two strings only — &$percent is a by-ref write (php-src string.c).
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[1]);
            case 'strncmp':
            case 'strncasecmp':
                // string, string, long len
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[2]);
            case 'strcmp':
            case 'strcasecmp':
            case 'strnatcmp':
            case 'strnatcasecmp':
            case 'strchr':
            case 'strrchr':
            case 'strpbrk':
            case 'str_contains':
            case 'str_starts_with':
            case 'str_ends_with':
                // two strings (strpbrk char_list empty → ValueError like explode '')
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[1]);
            case 'strpos':
            case 'stripos':
            case 'strrpos':
            case 'strripos':
            case 'strcspn':
            case 'strspn':
            case 'substr_count':
                // haystack string, needle string [, numeric…]
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[1])
                ) {
                    return false;
                }
                for ($i = 2, $n = count($callArgs); $i < $n; ++$i) {
                    if (
                        !$callArgs[$i] instanceof Variable
                        || !self::numericParamBuiltinArgCannotThrow($callArgs[$i])
                    ) {
                        return false;
                    }
                }

                return true;
            case 'strstr':
            case 'stristr':
                // haystack, needle [, before_needle bool]
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                // before_needle: bool/int/float scalars never throw.
                return $callArgs[2] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[2]);
            default:
                return false;
        }
    }
}
