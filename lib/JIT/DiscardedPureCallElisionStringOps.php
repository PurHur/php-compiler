<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Discarded pure-call elision for html-escape / slice-compare / pad-split /
 * replace-join / implode string builtins (#36387 / #23483).
 *
 * Extracted from {@see DiscardedPureCallElision} so the 6k-line hub is not one
 * monolith TU on the gen-0 spine (split-TU / size-budget ratchet). Shared
 * arg predicates ({@code stringArgAllowsDiscardedElision},
 * {@code mathArgAllowsDiscardedElision}, {@code isTypedArrayArg}) stay on the
 * hub class.
 *
 * Used via {@code use DiscardedPureCallElisionStringOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/standard/html.c, string.c, php_pcre.c, exec.c
 * (htmlspecialchars / substr / str_pad / str_replace / implode).
 */
trait DiscardedPureCallElisionStringOps
{
    /**
     * Discarded {@code htmlspecialchars}/{@code htmlentities}/{@code nl2br}/
     * {@code preg_quote}/{@code escapeshellarg}/… on typed string (+ optional
     * numeric flags / null encoding) — php-src {@code html.c}/{@code string.c}/
     * {@code php_pcre.c}/{@code exec.c}; soft-null string args stay live
     * (deprecate). Encoding {@code null} is Z_PARAM_STR_OR_NULL and is allowed.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureHtmlEscapeNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureHtmlEscapeBuiltin($name)) {
            return false;
        }

        return self::htmlEscapeArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function htmlEscapeArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'escapeshellarg':
            case 'escapeshellcmd':
                return isset($callArgs[0])
                    && $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && !isset($callArgs[1]);
            case 'preg_quote':
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
                    && (
                        self::stringArgAllowsDiscardedElision($callArgs[1])
                        || Variable::TYPE_NULL === $callArgs[1]->type
                        || $callArgs[1]->isNullConstant
                    );
            case 'nl2br':
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
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            case 'htmlspecialchars_decode':
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
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            case 'htmlspecialchars':
            case 'htmlentities':
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
                if (!isset($callArgs[2])) {
                    return true;
                }
                if (
                    !$callArgs[2] instanceof Variable
                    || !(
                        self::stringArgAllowsDiscardedElision($callArgs[2])
                        || Variable::TYPE_NULL === $callArgs[2]->type
                        || $callArgs[2]->isNullConstant
                    )
                ) {
                    return false;
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[3]);
            case 'html_entity_decode':
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
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && (
                        self::stringArgAllowsDiscardedElision($callArgs[2])
                        || Variable::TYPE_NULL === $callArgs[2]->type
                        || $callArgs[2]->isNullConstant
                    );
            default:
                return false;
        }
    }

    /**
     * Discarded {@code substr}/{@code str_repeat}/{@code strcmp}/{@code strpos}/
     * {@code strstr}/{@code strpbrk}/{@code str_contains}/{@code str_starts_with}/
     * {@code str_ends_with}/{@code levenshtein}/{@code similar_text}/… on typed
     * string (+ numeric) args — php-src {@code string.c}/{@code levenshtein.c}
     * Z_PARAM_STR / Z_PARAM_LONG family; soft null / int-needle deprecations /
     * {@code __toString} stay live (peer {@see tryElidePureStringTransformNoSideEffect}).
     * {@code similar_text} with {@code &$percent} stays live (by-ref write).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStringSliceOrCompareNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureStringSliceOrCompareBuiltin($name)) {
            return false;
        }

        return self::stringSliceOrCompareArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function stringSliceOrCompareArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'substr':
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::mathArgAllowsDiscardedElision($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[2]);
            case 'str_repeat':
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            case 'levenshtein':
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[1])
                ) {
                    return false;
                }
                for ($i = 2, $n = count($callArgs); $i < $n; ++$i) {
                    if ($i > 4) {
                        return false;
                    }
                    if (
                        !$callArgs[$i] instanceof Variable
                        || !self::mathArgAllowsDiscardedElision($callArgs[$i])
                    ) {
                        return false;
                    }
                }

                return true;
            case 'similar_text':
                // Two strings only — &$percent is a by-ref write.
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[1]);
            case 'strncmp':
            case 'strncasecmp':
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[2]);
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
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[1]);
            case 'strpos':
            case 'stripos':
            case 'strrpos':
            case 'strripos':
            case 'strcspn':
            case 'strspn':
            case 'substr_count':
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[1])
                ) {
                    return false;
                }
                for ($i = 2, $n = count($callArgs); $i < $n; ++$i) {
                    if (
                        !$callArgs[$i] instanceof Variable
                        || !self::mathArgAllowsDiscardedElision($callArgs[$i])
                    ) {
                        return false;
                    }
                }

                return true;
            case 'strstr':
            case 'stristr':
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code str_pad}/{@code chunk_split}/{@code wordwrap}/
     * {@code str_split}/{@code explode}/{@code str_getcsv} on typed string
     * (+ numeric) args — php-src {@code string.c}/{@code file.c} Z_PARAM_STR /
     * Z_PARAM_LONG family; soft null / {@code __toString} stay live.
     * {@code str_getcsv} without an explicit escape stays live (PHP 8.4+ DEP).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStringPadOrSplitNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureStringPadOrSplitBuiltin($name)) {
            return false;
        }

        return self::stringPadOrSplitArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function stringPadOrSplitArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'str_pad':
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::mathArgAllowsDiscardedElision($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }
                if (
                    !$callArgs[2] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[2])
                ) {
                    return false;
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[3]);
            case 'chunk_split':
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
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[2]);
            case 'wordwrap':
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
                if (!isset($callArgs[2])) {
                    return true;
                }
                if (
                    !$callArgs[2] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[2])
                ) {
                    return false;
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[3]);
            case 'str_split':
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
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            case 'explode':
                if (!isset($callArgs[0], $callArgs[1])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[1])
                ) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[2]);
            case 'str_getcsv':
                // All four strings required — omitted $escape DEP (php-src 8.4+).
                if (
                    !isset($callArgs[0], $callArgs[1], $callArgs[2], $callArgs[3])
                    || isset($callArgs[4])
                ) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[2])
                    && $callArgs[3] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[3]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code str_replace}/{@code str_ireplace}/{@code substr_replace}/
     * {@code strtr} on typed string (+ numeric) args — php-src {@code string.c}
     * string forms only. Array operands stay live (element {@code __toString});
     * {@code &$count} stays live (by-ref write); two-arg {@code strtr} stays live
     * (empty-replacement warnings / pair stringify). Soft null stays live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStringReplaceOrJoinNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureStringReplaceOrJoinBuiltin($name)) {
            return false;
        }

        return self::stringReplaceOrJoinArgsAllowDiscardedElision($name, $callArgs);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function stringReplaceOrJoinArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'str_replace':
            case 'str_ireplace':
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[2]);
            case 'substr_replace':
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !self::stringArgAllowsDiscardedElision($callArgs[1])
                    || !$callArgs[2] instanceof Variable
                    || !self::mathArgAllowsDiscardedElision($callArgs[2])
                ) {
                    return false;
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[3]);
            case 'strtr':
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }

                return $callArgs[0] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[0])
                    && $callArgs[1] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[1])
                    && $callArgs[2] instanceof Variable
                    && self::stringArgAllowsDiscardedElision($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code implode}/{@code join} on typed array pieces — php-src
     * {@code ext/standard/string.c} {@code php_implode}. Soft-null separator
     * stays live (deprecate). Array-first two-arg form stays live (legacy
     * order / PROFILE≥8.4 TypeError on {@code implode}). Soft-null /
     * non-array pieces stay live ({@code TypeError}). Peer typed-array
     * {@see tryElidePureArrayTransformNoSideEffect}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureImplodeJoinNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('implode' !== $name && 'join' !== $name) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (isset($callArgs[2])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            // One-arg: pieces array only.
            return self::isTypedArrayArg($callArgs[0]);
        }
        // Two-arg: separator string + pieces array (never array-first).
        if (
            !self::stringArgAllowsDiscardedElision($callArgs[0])
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }

        return self::isTypedArrayArg($callArgs[1]);
    }
}
