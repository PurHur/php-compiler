<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Discarded pure-call elision for sprintf/vsprintf, pathinfo, parse_url,
 * count_chars, str_word_count, strip_tags, get_html_translation_table, and
 * version_compare (#36387 / #23483).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub is not one monolith
 * TU on the gen-0 spine (split-TU / size-budget ratchet). Shared arg predicates
 * ({@code stringArgAllowsDiscardedElision}, {@code mathArgAllowsDiscardedElision},
 * {@code isTypedArrayArg}) stay on the hub class.
 *
 * Used via {@code use DiscardedPureCallElisionFormatAnalyzeOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/standard/formatted_print.c, string.c, html.c,
 * versioning.c, url.c / basic_functions.c (pathinfo).
 */
trait DiscardedPureCallElisionFormatAnalyzeOps
{
    /**
     * Discarded {@code sprintf} / {@code vsprintf} — php-src
     * {@code ext/standard/formatted_print.c}. Compile-time format whose
     * conversions are a non-positional subset ({@code sdiuoxXfFeEgGcb}) with
     * enough typed scalar args (string / numeric / bool). Incomplete /
     * positional / {@code *} width / {@code %a}/{@code %A} stay live
     * ({@code ArgumentCountError} / {@code ValueError}). Soft-null and
     * object/array value args stay live. {@code printf}/{@code fprintf}/
     * {@code vprintf}/{@code vfprintf} are never matched (IO side effects).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureSprintfNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('sprintf' !== $name && 'vsprintf' !== $name) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        $format = JitStringArg::compileTimeLiteral($callArgs[0]);
        if (null === $format) {
            // Soft-null / runtime format stay live (deprecate / ValueError).
            return false;
        }
        $required = self::compileTimeSprintfRequiredValueArgCount($format);
        if (null === $required) {
            return false;
        }
        if ('vsprintf' === $name) {
            // Element count / types inside the array are unknown — only elide
            // formats that need zero value args (literal text / %% only).
            if (0 !== $required) {
                return false;
            }
            if (2 !== \count($callArgs)) {
                return false;
            }
            if (!$callArgs[1] instanceof Variable || !self::isTypedArrayArg($callArgs[1])) {
                return false;
            }

            return true;
        }
        $argc = \count($callArgs);
        // format + required value args; extras are ignored by Zend.
        if ($argc < 1 + $required) {
            return false;
        }
        for ($i = 1; $i < $argc; ++$i) {
            if (
                !$callArgs[$i] instanceof Variable
                || !self::sprintfValueArgAllowsDiscardedElision($callArgs[$i])
            ) {
                return false;
            }
        }

        return true;
    }


    /**
     * Typed string / numeric / bool scalar — no null deprecate / {@code __toString}.
     */
    private static function sprintfValueArgAllowsDiscardedElision(Variable $arg): bool
    {
        if (self::stringArgAllowsDiscardedElision($arg)) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($arg);
    }


    /**
     * Count value arguments a compile-time sprintf format requires, or null when
     * the format has error/side-effect paths we refuse to elide.
     *
     * Rejects positional {@code %n$}, {@code *} width/precision, {@code %a}/
     * {@code %A} ({@code ValueError}), unknown specs, and a trailing incomplete
     * {@code %}. php-src: {@code ext/standard/formatted_print.c}.
     */
    private static function compileTimeSprintfRequiredValueArgCount(string $format): ?int
    {
        $len = \strlen($format);
        $needed = 0;
        for ($i = 0; $i < $len; ++$i) {
            if ('%' !== $format[$i]) {
                continue;
            }
            ++$i;
            if ($i >= $len) {
                // Trailing bare "%" — Zend ArgumentCountError / incomplete.
                return null;
            }
            if ('%' === $format[$i]) {
                continue;
            }
            // Positional "%n$" — stay live (arg indexing / missing-arg errors).
            if (self::sprintfFormatLooksPositional($format, $i)) {
                return null;
            }
            // Flags: '#0- +\' and space (php-src formatted_print.c).
            while (
                $i < $len
                && (
                    '#' === $format[$i]
                    || '0' === $format[$i]
                    || '-' === $format[$i]
                    || ' ' === $format[$i]
                    || '+' === $format[$i]
                    || "'" === $format[$i]
                )
            ) {
                ++$i;
            }
            if ($i >= $len) {
                return null;
            }
            // Width: digits only — "*" stays live (extra int arg + errors).
            if ('*' === $format[$i]) {
                return null;
            }
            while ($i < $len && $format[$i] >= '0' && $format[$i] <= '9') {
                ++$i;
            }
            if ($i >= $len) {
                return null;
            }
            if ('.' === $format[$i]) {
                ++$i;
                if ($i >= $len) {
                    return null;
                }
                if ('*' === $format[$i]) {
                    return null;
                }
                while ($i < $len && $format[$i] >= '0' && $format[$i] <= '9') {
                    ++$i;
                }
                if ($i >= $len) {
                    return null;
                }
            }
            $spec = $format[$i];
            // %a/%A → ValueError in this runtime (#29085); unknown → stay live.
            if (
                's' !== $spec && 'd' !== $spec && 'i' !== $spec && 'u' !== $spec
                && 'o' !== $spec && 'x' !== $spec && 'X' !== $spec
                && 'f' !== $spec && 'F' !== $spec && 'e' !== $spec && 'E' !== $spec
                && 'g' !== $spec && 'G' !== $spec && 'c' !== $spec && 'b' !== $spec
            ) {
                return null;
            }
            ++$needed;
        }

        return $needed;
    }


    /**
     * True when {@code $format[$i…]} begins a positional conversion ({@code 1$s}).
     */
    private static function sprintfFormatLooksPositional(string $format, int $i): bool
    {
        $len = \strlen($format);
        if ($i >= $len || $format[$i] < '1' || $format[$i] > '9') {
            return false;
        }
        $j = $i;
        while ($j < $len && $format[$j] >= '0' && $format[$j] <= '9') {
            ++$j;
        }

        return $j < $len && '$' === $format[$j];
    }


    /**
     * Discarded {@code pathinfo} on typed / literal string (+ optional typed
     * flags) — php-src {@code basic_functions.c}/{@code file.c}. Soft-null
     * path/flags stay live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePurePathinfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPurePathinfoBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::pathinfoArgsAllowDiscardedElision($callArgs);
    }


    /**
     * Discarded {@code parse_url} on typed / literal string (+ optional typed
     * component) — php-src {@code url.c}. Soft-null url/component stay live
     * (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureParseUrlNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureParseUrlBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::parseUrlArgsAllowDiscardedElision($callArgs);
    }


    /**
     * Discarded {@code count_chars} — php-src {@code ext/standard/string.c}
     * {@code PHP_FUNCTION(count_chars)}. Mode must be compile-time in [0, 4]
     * ({@code ValueError} otherwise). Soft-null string / mode stay live
     * (deprecate). Runtime typed mode stays live. Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCountCharsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('count_chars' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || isset($callArgs[2])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (!$callArgs[1] instanceof Variable) {
            return false;
        }
        $mode = $callArgs[1]->compileTimeLong;

        return null !== $mode && $mode >= 0 && $mode <= 4;
    }


    /**
     * Discarded {@code str_word_count} — php-src {@code ext/standard/string.c}
     * {@code PHP_FUNCTION(str_word_count)}. Format must be compile-time in
     * [0, 2] ({@code ValueError} otherwise). Soft-null string / format / chars
     * stay live (deprecate). Runtime typed format stays live. Excess argc
     * stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStrWordCountNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('str_word_count' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || isset($callArgs[3])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (!$callArgs[1] instanceof Variable) {
            return false;
        }
        $format = $callArgs[1]->compileTimeLong;
        if (null === $format || $format < 0 || $format > 2) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return true;
        }

        return $callArgs[2] instanceof Variable
            && self::stringArgAllowsDiscardedElision($callArgs[2]);
    }


    /**
     * Discarded {@code strip_tags} — php-src {@code ext/standard/string.c}
     * {@code PHP_FUNCTION(strip_tags)}. Subject must be a typed / literal
     * string. Optional {@code $allowed_tags} is typed string, typed array, or
     * null (strip all). Soft-null subject stays live (deprecate). Object /
     * value-box subject stays live ({@code __toString} / TypeError). Excess
     * argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStripTagsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('strip_tags' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || isset($callArgs[2])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (!$callArgs[1] instanceof Variable) {
            return false;
        }

        return self::stripTagsAllowedTagsAllowsDiscardedElision($callArgs[1]);
    }


    /**
     * {@code strip_tags} {@code $allowed_tags}: null, typed string, or typed
     * array — objects / generic value-boxes stay live ({@code TypeError}).
     */
    private static function stripTagsAllowedTagsAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return true;
        }
        if (self::stringArgAllowsDiscardedElision($arg)) {
            return true;
        }

        return self::isTypedArrayArg($arg);
    }


    /**
     * Discarded {@code get_html_translation_table} — php-src
     * {@code ext/standard/html.c}. Zero-arg OK. Optional table/flags must be
     * typed numeric (soft-null stays live — deprecate). Optional encoding must
     * be typed / literal string (unsupported charset only warns and assumes
     * UTF-8). Excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureGetHtmlTranslationTableNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('get_html_translation_table' !== strtolower($toCall->getName())) {
            return false;
        }
        if (isset($callArgs[3])) {
            return false;
        }
        if (isset($callArgs[0])) {
            if (
                !$callArgs[0] instanceof Variable
                || !self::htmlTranslationIntArgAllowsDiscardedElision($callArgs[0])
            ) {
                return false;
            }
        }
        if (isset($callArgs[1])) {
            if (
                !$callArgs[1] instanceof Variable
                || !self::htmlTranslationIntArgAllowsDiscardedElision($callArgs[1])
            ) {
                return false;
            }
        }
        if (isset($callArgs[2])) {
            if (!$callArgs[2] instanceof Variable || !self::stringArgAllowsDiscardedElision($callArgs[2])) {
                return false;
            }
        }

        return true;
    }


    /**
     * Table / flags for {@code get_html_translation_table}: typed numeric or a
     * named compile-time int constant ({@code HTML_SPECIALCHARS}, {@code ENT_*}).
     * Soft-null stays out (deprecate).
     */
    private static function htmlTranslationIntArgAllowsDiscardedElision(Variable $arg): bool
    {
        if (self::mathArgAllowsDiscardedElision($arg)) {
            return true;
        }

        return null !== ($arg->compileTimeConstantName ?? null);
    }


    /**
     * Discarded {@code version_compare} on typed / literal strings — php-src
     * {@code versioning.c}. Optional operator must be null or a compile-time
     * valid comparison op ({@code ValueError} otherwise).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureVersionCompareNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureVersionCompareBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::versionCompareArgsAllowDiscardedElision($callArgs);
    }


    /**
     * @param array<int, Variable> $callArgs
     */
    private static function pathinfoArgsAllowDiscardedElision(array $callArgs): bool
    {
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
            || isset($callArgs[2])
        ) {
            return false;
        }

        return true;
    }


    /**
     * @param array<int, Variable> $callArgs
     */
    private static function parseUrlArgsAllowDiscardedElision(array $callArgs): bool
    {
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
            || isset($callArgs[2])
        ) {
            return false;
        }

        return true;
    }


    /**
     * @param array<int, Variable> $callArgs
     */
    private static function versionCompareArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !self::stringArgAllowsDiscardedElision($callArgs[0])
            || !self::stringArgAllowsDiscardedElision($callArgs[1])
        ) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return true;
        }
        if (!$callArgs[2] instanceof Variable || isset($callArgs[3])) {
            return false;
        }
        if ($callArgs[2]->isNullConstant || Variable::TYPE_NULL === $callArgs[2]->type) {
            return true;
        }
        $op = JitStringArg::compileTimeLiteral($callArgs[2]);
        if (null === $op) {
            return false;
        }

        return NoThrowCallElision::isValidVersionCompareOperatorLiteral($op);
    }
}
