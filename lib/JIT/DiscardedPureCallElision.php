<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\VM\Variable as VmVariable;

/**
 * Elide discarded calls to compile-time-pure builtins (#23483 / #36386 call-overhead).
 *
 * php-src: ZPP may still run user-visible coercions; here we only fold cases that are
 * side-effect-free (literal / typed-string strlen/ord/strtolower/ucwords/bin2hex/
 * urlencode/str_rot13/quotemeta/md5/crc32/base64_encode/soundex/…, typed
 * substr/str_repeat/strcmp/strpos/strstr/str_contains/str_starts_with/
 * str_ends_with/levenshtein/…, typed str_pad/chunk_split/wordwrap/str_split/
 * explode/str_getcsv, typed str_replace/str_ireplace/substr_replace/strtr
 * (string forms), typed addcslashes/stripcslashes/strpbrk,
 * typed quoted_printable_encode/decode, basename/dirname,
 * typed htmlspecialchars/htmlentities/nl2br/preg_quote/
 * escapeshell*, typed-numeric chr/number_format, typed similar_text
 * (2-arg), typed intval/floatval/boolval/strval, typed decbin/dechex/
 * decoct / bindec/hexdec/octdec / base_convert (compile-time bases in
 * [2,36]), typed ip2long/long2ip/inet_pton/inet_ntop, typed version_compare,
 * typed min/max/fmin/fmax (≥1 numeric; array-form min/max stays live),
 * typed checkdate (3 longs), typed hash_equals (2 strings),
 * typed pathinfo (string + optional flags), typed parse_url
 * (string + optional component), typed function_exists /
 * extension_loaded / defined (single string; no autoload), typed
 * method_exists / property_exists (object + string; string class
 * names stay live for autoload), typed array_key_exists /
 * key_exists (typed array + non-null scalar key), typed
 * class_exists / interface_exists / trait_exists / enum_exists
 * (string + compile-time-false {@code $autoload}),
 * typed-object get_class / get_parent_class / spl_object_id /
 * spl_object_hash (string get_parent_class / zero-arg stay live),
 * typed-object is_a / is_subclass_of (string subjects stay live for
 * autoload; soft-null class / allow_string stay live),
 * typed-object class_parents / class_implements / class_uses (string
 * subjects stay live for autoload; soft-null autoload stays live),
 * typed-object get_object_vars / get_mangled_object_vars /
 * get_class_methods (string get_class_methods stays live for autoload),
 * zero-arg get_declared_classes / get_declared_interfaces /
 * get_declared_traits / get_included_files / get_required_files /
 * php_sapi_name / zend_version (excess argc stays live —
 * ArgumentCountError),
 * get_loaded_extensions / get_defined_constants / get_defined_functions
 * (zero-arg or typed bool; soft-null bool stays live — deprecate;
 * excess argc stays live — ArgumentCountError),
 * phpversion / php_uname (zero-arg or typed string; soft-null stays
 * live — deprecate; excess argc stays live — ArgumentCountError),
 * getmypid / getmyuid / getmygid (zero-arg; excess argc stays live —
 * ArgumentCountError),
 * zero-arg pi, type.c predicates + gettype/get_debug_type, ctype.c
 * classifiers on typed/literal strings, typed-array count/sizeof, math.c
 * incl. pow/fpow/fdiv on already-numeric args, empty void user functions).
 * Soft-null strlen / ord / chr / math / string / ctype / inet coercions are
 * NOT elided — they emit deprecations (PHP 8.1+). Countable objects stay live
 * (user {@code count()} handlers). {@code intdiv} is never discarded here
 * (DivisionByZeroError must stay observable). {@code hex2bin}/
 * {@code base64_decode}/{@code convert_uudecode} stay live (invalid-input
 * warnings / false returns). Int needles for {@code strpos}/{@code strchr}/…
 * stay live (PHP 8 deprecations). Array {@code str_replace}/{@code implode}
 * stay live (element {@code __toString}); {@code str_replace} {@code &$count}
 * stays live (by-ref write). {@code dirname} with a non-constant {@code $levels}
 * stays live ({@code ValueError} when {@code $levels < 1}). {@code str_getcsv}
 * without an explicit {@code $escape} stays live (PHP 8.4+ omitted-escape DEP).
 * {@code similar_text} with {@code &$percent} stays live (by-ref write).
 * {@code strval} on arrays/objects stays live (array-to-string warning /
 * {@code __toString}). {@code base_convert} with non-constant bases stays
 * live ({@code ValueError} outside [2,36]). {@code version_compare} with an
 * unknown typed operator stays live ({@code ValueError} on invalid ops).
 * Single-array {@code min}/{@code max} stays live (element compare / object
 * handlers). {@code clamp} stays live ({@code ValueError} when min > max /
 * NAN). Soft-null {@code checkdate} stays live (deprecate). Non-string
 * {@code hash_equals} stays live ({@code TypeError}). Soft-null
 * {@code pathinfo}/{@code parse_url} path/url/flags/component stay live
 * (deprecate). Soft-null {@code function_exists}/{@code extension_loaded}/
 * {@code defined} stay live (deprecate). {@code class_exists} /
 * {@code interface_exists} / {@code trait_exists} / {@code enum_exists}
 * without compile-time-false {@code $autoload} stay live (default true
 * runs spl_autoload). Soft-null
 * method/property names and string class-name receivers for
 * {@code method_exists}/{@code property_exists} stay live (deprecate /
 * autoload). Soft-null {@code array_key_exists}/{@code key_exists} keys
 * stay live (null-key deprecation); object / value-box keys stay live
 * ({@code TypeError} / unknown). Non-array haystacks stay live
 * ({@code TypeError}). Soft-null / non-object {@code get_class}/
 * {@code get_parent_class}/{@code spl_object_*} stay live ({@code TypeError});
 * string {@code get_parent_class} stays live (autoload); zero-arg
 * {@code get_class}/{@code get_parent_class} stay live (deprecation / scope).
 * Soft-null / non-object {@code is_a}/{@code is_subclass_of} subjects and
 * soft-null class / allow_string stay live; string subjects stay live
 * (autoload when allow_string). Soft-null / non-object
 * {@code class_parents}/{@code class_implements}/{@code class_uses}
 * subjects and soft-null {@code $autoload} stay live; string subjects
 * stay live (autoload). Soft-null / non-object
 * {@code get_object_vars}/{@code get_mangled_object_vars}/
 * {@code get_class_methods} stay live ({@code TypeError}); string
 * {@code get_class_methods} stays live (autoload). Non-zero-arg
 * {@code get_declared_*}/{@code get_included_files}/{@code get_required_files}/
 * {@code php_sapi_name}/{@code zend_version} stay live
 * ({@code ArgumentCountError}). Soft-null
 * {@code get_loaded_extensions}/{@code get_defined_constants}/
 * {@code get_defined_functions} bool flags stay live (deprecate); excess
 * argc stays live ({@code ArgumentCountError}). Soft-null
 * {@code phpversion}/{@code php_uname} stay live (deprecate); excess argc
 * and non-string modes stay live; non-zero-arg {@code getmypid}/
 * {@code getmyuid}/{@code getmygid} stay live ({@code ArgumentCountError}).
 */
final class DiscardedPureCallElision
{
    /**
     * @param array<int, Variable> $callArgs
     */
    public static function tryElide(Context $context, ?Call $toCall, array $callArgs): bool
    {
        if (self::tryElidePureTypePredicate($toCall)) {
            return true;
        }
        if (self::tryElidePureCtypeNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElideStrlenNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElideOrdNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElideChrNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureStringTransformNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureHtmlEscapeNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureStringSliceOrCompareNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureStringPadOrSplitNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureStringReplaceOrJoinNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureNumberFormatNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureScalarCastNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureBaseConvertNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureInetNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureMinMaxNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCheckdateNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureHashEqualsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePurePathinfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureParseUrlNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureFunctionExistsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureExtensionLoadedNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureDefinedNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureMethodExistsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePurePropertyExistsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureArrayKeyExistsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureClassExistsFamilyNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureObjectIntrospectNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureIsAFamilyNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureClassHierarchyNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureObjectVarsMethodsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureZeroArgRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureDefinedTableRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureProcessIdentityNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureVersionCompareNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElideCountOnTypedArray($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureMathNoSideEffect($toCall, $callArgs)) {
            return true;
        }

        return self::tryElideEffectFreeVoidNative($context, $toCall, $callArgs);
    }

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
     * Discarded {@code function_exists} on typed / literal string — php-src
     * {@code Zend/zend_builtin_functions.c}. Soft-null stays live (deprecate).
     * No autoload side effects (unlike {@code class_exists}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureFunctionExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureFunctionExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::functionExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code extension_loaded} on typed / literal string — php-src
     * {@code ext/standard/info.c}. Soft-null stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureExtensionLoadedNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureExtensionLoadedBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::extensionLoadedArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code defined} on typed / literal string — php-src
     * {@code ext/standard/basic_functions.c}. Soft-null stays live (deprecate).
     * No autoload side effects.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDefinedNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureDefinedBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::definedArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code method_exists} on typed object + typed / literal method
     * string — php-src {@code Zend/zend_builtin_functions.c}. String class-name
     * receivers stay live (autoload). Soft-null method stays live (deprecate).
     * Null / non-object|string receivers stay live ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMethodExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureMethodExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::methodExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code array_key_exists}/{@code key_exists} on typed array +
     * non-null scalar key — php-src {@code ext/standard/array.c}. Soft-null
     * keys stay live (deprecate). Object / value-box keys stay live. Non-array
     * haystacks stay live ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayKeyExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureArrayKeyExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::arrayKeyExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code property_exists} on typed object + typed / literal
     * property string — php-src {@code Zend/zend_builtin_functions.c}. Peer
     * {@see tryElidePureMethodExistsNoSideEffect}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePurePropertyExistsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPurePropertyExistsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::propertyExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code class_exists}/{@code interface_exists}/
     * {@code trait_exists}/{@code enum_exists} on typed / literal string +
     * compile-time-false {@code $autoload} — php-src
     * {@code Zend/zend_builtin_functions.c}. Default / true / dynamic
     * autoload stays live (spl_autoload). Soft-null name/autoload stay live
     * (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureClassExistsFamilyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureClassExistsFamilyBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::classExistsFamilyArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code get_class}/{@code get_parent_class}/{@code spl_object_id}/
     * {@code spl_object_hash} on a typed object — php-src
     * {@code Zend/zend_builtin_functions.c} / {@code ext/spl/php_spl.c}. String
     * {@code get_parent_class} stays live (autoload). Soft-null / non-object
     * stay live ({@code TypeError}). Zero-arg stay live (deprecation / scope).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureObjectIntrospectNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureObjectIntrospectBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::objectIntrospectArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code is_a}/{@code is_subclass_of} on a typed object + typed /
     * literal class string — php-src {@code Zend/zend_builtin_functions.c}.
     * Object subjects never autoload; string subjects stay live. Soft-null
     * class / allow_string stay live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureIsAFamilyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureIsAFamilyBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::isAFamilyArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code class_parents}/{@code class_implements}/{@code class_uses}
     * on a typed object (+ optional typed bool {@code $autoload}) — php-src
     * {@code ext/standard/class.c}/{@code basic_functions.c}/{@code spl_functions.c}.
     * Object subjects never autoload; string subjects stay live. Soft-null
     * {@code $autoload} stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureClassHierarchyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureClassHierarchyBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::classHierarchyArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code get_object_vars}/{@code get_mangled_object_vars}/
     * {@code get_class_methods} on a typed object — php-src
     * {@code Zend/zend_builtin_functions.c}/{@code ext/standard/var.c}.
     * Object operands never autoload; string {@code get_class_methods} stays
     * live. Soft-null / non-object stay live ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureObjectVarsMethodsNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureObjectVarsMethodsBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::objectVarsMethodsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded zero-arg {@code get_declared_classes}/
     * {@code get_declared_interfaces}/{@code get_declared_traits}/
     * {@code get_included_files}/{@code get_required_files}/
     * {@code php_sapi_name}/{@code zend_version} — php-src
     * {@code basic_functions.c}/{@code info.c}/{@code Zend/zend.c}. Table /
     * SAPI reads with no user handlers. Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureZeroArgRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureZeroArgRuntimeInfoBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::zeroArgRuntimeInfoArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code get_loaded_extensions}/{@code get_defined_constants}/
     * {@code get_defined_functions} with zero args or a typed bool flag —
     * php-src {@code basic_functions.c}/{@code info.c}. Table reads with no
     * user handlers. Soft-null bool stays live (deprecate). Excess argc stays
     * live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDefinedTableRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureDefinedTableRuntimeInfoBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::definedTableRuntimeInfoArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code phpversion}/{@code php_uname}/{@code getmypid}/
     * {@code getmyuid}/{@code getmygid} — php-src {@code info.c}/
     * {@code basic_functions.c}. Pure process / runtime identity reads.
     * Soft-null optional string stays live (deprecate). Excess argc stays
     * live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureProcessIdentityNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $nameLc = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureProcessIdentityBuiltin($nameLc)) {
            return false;
        }

        return self::processIdentityArgsAllowDiscardedElision($nameLc, $callArgs);
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
    private static function baseConvertArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'decbin':
            case 'dechex':
            case 'decoct':
                return !isset($callArgs[1])
                    && self::mathArgAllowsDiscardedElision($callArgs[0]);
            case 'bindec':
            case 'hexdec':
            case 'octdec':
                return !isset($callArgs[1])
                    && self::stringArgAllowsDiscardedElision($callArgs[0]);
            case 'base_convert':
                // string, long from_base∈[2,36], long to_base∈[2,36]
                if (
                    !isset($callArgs[1], $callArgs[2])
                    || isset($callArgs[3])
                    || !self::stringArgAllowsDiscardedElision($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }

                return NoThrowCallElision::compileTimeRadixBaseInRange($callArgs[1])
                    && NoThrowCallElision::compileTimeRadixBaseInRange($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function inetArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[1])) {
            return false;
        }
        switch ($nameLc) {
            case 'ip2long':
            case 'inet_pton':
            case 'inet_ntop':
                return self::stringArgAllowsDiscardedElision($callArgs[0]);
            case 'long2ip':
                return self::mathArgAllowsDiscardedElision($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Typed numeric scalars only. Single-array {@code min}/{@code max} stays live.
     * {@code fmin}/{@code fmax} need ≥2 args (ArgumentCountError otherwise).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function minMaxArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        if (('fmin' === $nameLc || 'fmax' === $nameLc) && \count($callArgs) < 2) {
            return false;
        }
        if (1 === \count($callArgs) && self::isTypedArrayArg($callArgs[0])) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function checkdateArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1], $callArgs[2])
            || isset($callArgs[3])
        ) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
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
    private static function functionExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || isset($callArgs[1])
        ) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function extensionLoadedArgsAllowDiscardedElision(array $callArgs): bool
    {
        return self::functionExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Exactly one typed / literal string — soft-null stays live (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function definedArgsAllowDiscardedElision(array $callArgs): bool
    {
        return self::functionExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Typed hashtable / native array + non-null scalar key (string / long /
     * double / bool). Soft-null keys deprecate; object / value-box keys stay
     * live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function arrayKeyExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[1])) {
            return false;
        }
        $key = $callArgs[0];
        if ($key->isNullConstant || Variable::TYPE_NULL === $key->type) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $key->type || Variable::TYPE_VALUE === $key->type) {
            return false;
        }
        if (self::stringArgAllowsDiscardedElision($key)) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($key);
    }

    /**
     * Typed object + typed / literal method string — string class names /
     * soft-null / value-box stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function methodExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }
        if (Variable::TYPE_OBJECT !== $callArgs[0]->type) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function propertyExistsArgsAllowDiscardedElision(array $callArgs): bool
    {
        return self::methodExistsArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Typed / literal class name + compile-time-false {@code $autoload}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function classExistsFamilyArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !self::stringArgAllowsDiscardedElision($callArgs[0])
        ) {
            return false;
        }

        return NoThrowCallElision::isCompileTimeFalseAutoloadArg($callArgs[1]);
    }

    /**
     * Exactly one typed object — peer {@see NoThrowCallElision::objectIntrospectArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function objectIntrospectArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::objectIntrospectArgsCannotThrow($callArgs);
    }

    /**
     * Typed object + typed / literal class string + optional non-null bool-ish
     * {@code $allow_string} — peer {@see NoThrowCallElision::isAFamilyArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function isAFamilyArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::isAFamilyArgsCannotThrow($callArgs);
    }

    /**
     * Typed object (+ optional non-null bool-ish {@code $autoload}) — peer
     * {@see NoThrowCallElision::classHierarchyArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function classHierarchyArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::classHierarchyArgsCannotThrow($callArgs);
    }

    /**
     * Typed object only — peer {@see NoThrowCallElision::objectVarsMethodsArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function objectVarsMethodsArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::objectVarsMethodsArgsCannotThrow($callArgs);
    }

    /**
     * Exactly zero arguments — peer {@see NoThrowCallElision::zeroArgRuntimeInfoArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function zeroArgRuntimeInfoArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::zeroArgRuntimeInfoArgsCannotThrow($callArgs);
    }

    /**
     * Zero args or typed bool flag — peer
     * {@see NoThrowCallElision::definedTableRuntimeInfoArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function definedTableRuntimeInfoArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::definedTableRuntimeInfoArgsCannotThrow($callArgs);
    }

    /**
     * {@code getmypid}/{@code getmyuid}/{@code getmygid}: arity 0.
     * {@code phpversion}/{@code php_uname}: arity 0 or one typed / literal
     * string (soft-null / non-string stay live — deprecate / coerce).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function processIdentityArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        switch ($nameLc) {
            case 'getmypid':
            case 'getmyuid':
            case 'getmygid':
                return [] === $callArgs;
            case 'phpversion':
            case 'php_uname':
                if ([] === $callArgs) {
                    return true;
                }
                if (
                    !isset($callArgs[0])
                    || !$callArgs[0] instanceof Variable
                    || isset($callArgs[1])
                ) {
                    return false;
                }

                return self::stringArgAllowsDiscardedElision($callArgs[0]);
            default:
                return false;
        }
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

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function scalarCastArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'intval':
                if (!self::scalarCastValueArgAllowsDiscardedElision($callArgs[0])) {
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
            case 'floatval':
            case 'doubleval':
            case 'boolval':
                if (isset($callArgs[1])) {
                    return false;
                }
                if ('boolval' === $nameLc && self::isTypedArrayArg($callArgs[0])) {
                    return true;
                }

                return self::scalarCastValueArgAllowsDiscardedElision($callArgs[0]);
            case 'strval':
                // Arrays warn; objects invoke __toString — scalars / null only.
                return !isset($callArgs[1])
                    && self::scalarCastValueArgAllowsDiscardedElision($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Typed string / numeric / bool / null — no object / value-box / hashtable.
     */
    private static function scalarCastValueArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return true;
        }
        if (self::stringArgAllowsDiscardedElision($arg)) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($arg);
    }

    /**
     * Discarded {@code count}/{@code sizeof} on a typed array — php-src
     * {@code Zend/zend_builtin_functions.c} PHP_FUNCTION(count) only reads the
     * HashTable when the value is an array. Countable objects invoke user
     * {@code count()} and must stay live; null TypeErrors stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideCountOnTypedArray(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('count' !== $name && 'sizeof' !== $name) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[0])) {
            return false;
        }
        if (isset($callArgs[1])) {
            // Optional $mode — null soft-deprecates (#31463); keep live.
            if (!$callArgs[1] instanceof Variable || !self::mathArgAllowsDiscardedElision($callArgs[1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Already a string slot or compile-time string literal — no Z_PARAM_STR
     * coerce / null deprecate / {@code __toString}.
     */
    private static function stringArgAllowsDiscardedElision(Variable $arg): bool
    {
        if (null !== JitStringArg::compileTimeLiteral($arg)) {
            return true;
        }

        return Variable::TYPE_STRING === $arg->type;
    }

    /**
     * Typed hashtable or packed native array — not Countable / value-box.
     */
    private static function isTypedArrayArg(Variable $arg): bool
    {
        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            return true;
        }

        return Variable::TYPE_HASHTABLE === $arg->type;
    }

    /**
     * Discarded {@code abs}/{@code sqrt}/{@code floor}/…/{@code pi} on already-numeric
     * args (or zero-arg {@code pi}) — php-src {@code math.c} has no user handlers;
     * null soft-coercion deprecates so TYPE_NULL is excluded (peer strlen null).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMathNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureMathBuiltin($name)) {
            return false;
        }
        if ([] === $callArgs) {
            // pi() only — other math.c entries require at least one numeric arg.
            return 'pi' === $name;
        }
        if ('pi' === $name) {
            // Extra args stay live (ArgumentCountError).
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Already a numeric scalar — no Z_PARAM_* coerce / null deprecate / __toString.
     */
    private static function mathArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        if (null !== $arg->compileTimeLong || null !== $arg->compileTimeFloat) {
            return true;
        }
        if (
            Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type
        ) {
            return true;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);

        return null !== $lit && is_numeric($lit);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function tryElideEffectFreeVoidNative(Context $context, ?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof Native) {
            return false;
        }
        $lc = strtolower($toCall->name);
        if (!isset($context->discardedCallElisionVoidNatives[$lc])) {
            return false;
        }

        return self::nativeArgsAllowElision($toCall, $callArgs, $context);
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    private static function nativeArgsAllowElision(Native $call, array $callArgs, Context $context): bool
    {
        if ([] !== $call->paramByRefByArg) {
            return false;
        }
        if (
            [] !== $call->paramIntersectionConstraintsByArg
            || [] !== $call->paramDnfConstraintsByArg
            || [] !== $call->paramClassConstraintsByArg
        ) {
            return false;
        }
        if (null !== $call->variadicArgIndex) {
            return false;
        }
        foreach ($call->paramTypeConstraintsByArg as $idx => $constraint) {
            if (!isset($callArgs[$idx]) || !$callArgs[$idx] instanceof Variable) {
                continue;
            }
            if (!self::compileTimeArgSatisfiesConstraint($callArgs[$idx], $constraint, $context->callerStrictTypes)) {
                return false;
            }
        }

        return true;
    }

    private static function compileTimeArgSatisfiesConstraint(
        Variable $arg,
        int $constraint,
        bool $strict
    ): bool {
        switch ($constraint) {
            case VmVariable::TYPE_STRING:
                if (null !== JitStringArg::compileTimeLiteral($arg)) {
                    return true;
                }
                if ($strict) {
                    return false;
                }

                return null !== $arg->compileTimeLong
                    || Variable::TYPE_NATIVE_LONG === $arg->type
                    || Variable::TYPE_NATIVE_DOUBLE === $arg->type
                    || Variable::TYPE_NATIVE_BOOL === $arg->type;
            case VmVariable::TYPE_INTEGER:
                if (null !== $arg->compileTimeLong) {
                    return true;
                }
                if (Variable::TYPE_NATIVE_LONG === $arg->type) {
                    return true;
                }
                if ($strict) {
                    return false;
                }
                if (Variable::TYPE_NATIVE_BOOL === $arg->type || Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
                    return true;
                }
                $literal = JitStringArg::compileTimeLiteral($arg);

                return null !== $literal && is_numeric($literal);
            case VmVariable::TYPE_FLOAT:
                if (null !== $arg->compileTimeFloat) {
                    return true;
                }
                if (Variable::TYPE_NATIVE_DOUBLE === $arg->type) {
                    return true;
                }
                if ($strict) {
                    return false;
                }

                return null !== $arg->compileTimeLong
                    || Variable::TYPE_NATIVE_LONG === $arg->type
                    || (null !== ($lit = JitStringArg::compileTimeLiteral($arg)) && is_numeric($lit));
            case VmVariable::TYPE_BOOL:
                if (Variable::TYPE_NATIVE_BOOL === $arg->type) {
                    return true;
                }
                if ($strict) {
                    return false;
                }

                return null !== $arg->compileTimeLong
                    || Variable::TYPE_NATIVE_LONG === $arg->type;
            default:
                return false;
        }
    }
}
