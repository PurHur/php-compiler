<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Type / ctype / string transform / html-escape / slice-compare / pad-split /
 * replace-join no-throw proofs for {@see NoThrowCallElision} (#36387).
 *
 * Extracted so gen-0 spine gets another TU after ExistsConvertAndIntrospectOps
 * (#37588). External call sites keep using {@code NoThrowCallElision::…}
 * (trait methods on the hub class).
 *
 * Used via {@code use NoThrowCallElisionStringOps;} on {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: ext/standard/{type,string,html,url,md5,crc32,base64,
 * quot_print,levenshtein}.c; ext/ctype/ctype.c; ext/pcre/php_pcre.c;
 * ext/standard/file.c (str_getcsv / basename / dirname); ext/standard/exec.c.
 */
trait NoThrowCallElisionStringOps
{

    /**
     * php-src {@code ext/standard/type.c} predicates that only inspect zval type
     * tags (no autoload, no {@code __invoke}, no user handlers).
     *
     * Public for {@see DiscardedPureCallElision} — discarded statements of these
     * builtins are side-effect-free (#36386 untyped call overhead).
     */
    public static function isPureTypePredicateBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'is_int':
            case 'is_integer':
            case 'is_long':
            case 'is_float':
            case 'is_double':
            case 'is_real':
            case 'is_string':
            case 'is_bool':
            case 'is_null':
            case 'is_array':
            case 'is_object':
            case 'is_resource':
            case 'is_scalar':
            case 'is_numeric':
            case 'is_iterable':
            case 'is_countable':
            case 'is_finite':
            case 'is_infinite':
            case 'is_nan':
            // basic_functions.c gettype — type-tag → string label only (peer is_*).
            case 'gettype':
            // type.c get_debug_type — precise type name / class name only (no
            // __toString); peer gettype for discarded elision (#36386).
            case 'get_debug_type':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/ctype/ctype.c} classifiers — byte-class checks on an
     * already-string value (no user handlers). Public for
     * {@see DiscardedPureCallElision}: discarded statements on typed / literal
     * strings are side-effect-free (#36386). Int / null args still deprecate
     * (ctype_fallback) and must stay live when discarded.
     */
    public static function isPureCtypeBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'ctype_alnum':
            case 'ctype_alpha':
            case 'ctype_cntrl':
            case 'ctype_digit':
            case 'ctype_graph':
            case 'ctype_lower':
            case 'ctype_print':
            case 'ctype_punct':
            case 'ctype_space':
            case 'ctype_upper':
            case 'ctype_xdigit':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/string.c} transforms that only read a string
     * and allocate a result (no user handlers when the arg is already a string).
     *
     * Public for {@see DiscardedPureCallElision} — discarded statements are
     * side-effect-free on typed / literal string args (#36386). Soft null /
     * object {@code __toString} coercions are excluded by the caller.
     */
    public static function isPureStringTransformBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'strtolower':
            case 'strtoupper':
            case 'lcfirst':
            case 'ucfirst':
            case 'ucwords':
            case 'strrev':
            case 'trim':
            case 'ltrim':
            case 'rtrim':
            case 'addslashes':
            case 'stripslashes':
            case 'addcslashes':
            case 'stripcslashes':
            case 'bin2hex':
            // url.c / string.c — Z_PARAM_STR only; soft null deprecate stays live.
            case 'urlencode':
            case 'rawurlencode':
            case 'urldecode':
            case 'rawurldecode':
            case 'str_rot13':
            case 'quotemeta':
            // Hash / encode family — Z_PARAM_STR (+ optional typed bool/int that
            // fails stringArgAllowsDiscardedElision when present). Soft null /
            // hex2bin invalid-input warnings stay live (not listed).
            case 'md5':
            case 'sha1':
            case 'crc32':
            case 'crc32c':
            case 'base64_encode':
            case 'soundex':
            case 'metaphone':
            case 'convert_uuencode':
            case 'hebrev':
            case 'hebrevc':
            // quot_print.c / basename.c / file.c — Z_PARAM_STR (+ optional typed
            // trailing args handled by stringTransformArgsCannotThrow).
            case 'quoted_printable_encode':
            case 'quoted_printable_decode':
            case 'basename':
            case 'dirname':
                return true;
            default:
                return false;
        }
    }

    /**
     * Arg proofs for {@see isPureStringTransformBuiltin} — mixed STR + optional
     * LONG/BOOL trailing params. Public for symmetry with other string families.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function stringTransformArgsCannotThrow(string $nameLc, array $callArgs): bool
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

                return !isset($callArgs[2]);
            case 'dirname':
                // ValueError when levels < 1 — only compile-time levels≥1 prove.
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
                if (!$callArgs[1] instanceof Variable || isset($callArgs[2])) {
                    return false;
                }

                return null !== $callArgs[1]->compileTimeLong
                    && $callArgs[1]->compileTimeLong >= 1;
            case 'basename':
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
                    && self::stringParamBuiltinArgCannotThrow($callArgs[1])
                    && !isset($callArgs[2]);
            case 'quoted_printable_encode':
            case 'quoted_printable_decode':
                return isset($callArgs[0])
                    && $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && !isset($callArgs[1]);
            default:
                foreach ($callArgs as $arg) {
                    if (!$arg instanceof Variable || !self::stringParamBuiltinArgCannotThrow($arg)) {
                        return false;
                    }
                }

                return true;
        }
    }

    /**
     * php-src {@code ext/standard/html.c} / {@code string.c} {@code nl2br} /
     * {@code ext/pcre/php_pcre.c} {@code preg_quote} / {@code exec.c}
     * escapeshell* — read typed strings (+ optional long/bool flags); no user
     * handlers. Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureHtmlEscapeBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'htmlspecialchars':
            case 'htmlentities':
            case 'htmlspecialchars_decode':
            case 'html_entity_decode':
            case 'nl2br':
            case 'preg_quote':
            case 'escapeshellarg':
            case 'escapeshellcmd':
                return true;
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function htmlEscapeArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        switch ($nameLc) {
            case 'escapeshellarg':
            case 'escapeshellcmd':
                return isset($callArgs[0])
                    && $callArgs[0] instanceof Variable
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    && !isset($callArgs[1]);
            case 'preg_quote':
                // string [, string|null delimiter]
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
                    && (
                        self::stringParamBuiltinArgCannotThrow($callArgs[1])
                        || Variable::TYPE_NULL === $callArgs[1]->type
                        || $callArgs[1]->isNullConstant
                    );
            case 'nl2br':
                // string [, bool use_xhtml]
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
            case 'htmlspecialchars_decode':
                // string [, long flags]
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
            case 'htmlspecialchars':
            case 'htmlentities':
                // string [, long flags [, string|null encoding [, bool double_encode]]]
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
                    || !(
                        self::stringParamBuiltinArgCannotThrow($callArgs[2])
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
                    && self::numericParamBuiltinArgCannotThrow($callArgs[3]);
            case 'html_entity_decode':
                // string [, long flags [, string|null encoding]]
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
                    && (
                        self::stringParamBuiltinArgCannotThrow($callArgs[2])
                        || Variable::TYPE_NULL === $callArgs[2]->type
                        || $callArgs[2]->isNullConstant
                    );
            default:
                return false;
        }
    }

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
