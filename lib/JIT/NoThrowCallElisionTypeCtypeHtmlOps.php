<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Type / ctype / string-transform / html-escape no-throw proofs for
 * {@see NoThrowCallElision} (#36387).
 *
 * Renamed from {@see NoThrowCallElisionStringOps} after slice/pad/replace moved
 * to {@see NoThrowCallElisionStringSlicePadReplaceOps} (#37604) so the remaining
 * type/ctype/html TU has an honest name and gen-0 spine stays granular.
 * External call sites keep using {@code NoThrowCallElision::…}
 * (trait methods on the hub class).
 *
 * Used via {@code use NoThrowCallElisionTypeCtypeHtmlOps;} on
 * {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: ext/standard/{type,string,html,url,md5,crc32,base64,
 * quot_print,levenshtein}.c; ext/ctype/ctype.c; ext/pcre/php_pcre.c;
 * ext/standard/exec.c.
 */
trait NoThrowCallElisionTypeCtypeHtmlOps
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
}
