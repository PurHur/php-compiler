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
 * hash / hash_hmac (compile-time known algo + typed string data/key +
 * optional typed binary; options / unknown algo / soft-null stay live —
 * ValueError / deprecate),
 * sprintf (compile-time format with only non-positional width/precision
 * specs in {s,d,i,u,o,x,X,f,F,e,E,g,G,c,b} + enough typed scalar args;
 * {@code %%} free; {@code %*}/{@code %n$}/{@code %a}/{@code %A}/incomplete
 * {@code %} stay live — ArgumentCountError / ValueError; soft-null /
 * object / array args stay live — deprecate / {@code __toString}; extra
 * args OK; {@code printf}/{@code fprintf} never elided — stdout/IO),
 * vsprintf (same format rules + typed array only when the format needs
 * zero value args; non-empty conversion formats stay live — element
 * count / {@code __toString} unknown),
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
 * getmypid / getmyuid / getmygid / getmyinode / getlastmod /
 * get_current_user (zero-arg; excess argc stays live —
 * ArgumentCountError),
 * memory_get_usage / memory_get_peak_usage (zero-arg or typed bool;
 * soft-null bool stays live — deprecate / TypeError; excess argc stays
 * live — ArgumentCountError),
 * php_ini_loaded_file / php_ini_scanned_files / gc_enabled (zero-arg;
 * excess argc stays live — ArgumentCountError),
 * sys_get_temp_dir / getcwd / get_include_path / ob_get_level /
 * connection_status / connection_aborted / session_status / localeconv /
 * gc_status (zero-arg; excess argc stays live — ArgumentCountError),
 * gethostname / error_get_last / hash_algos / hash_hmac_algos /
 * ob_get_contents / ob_get_length / headers_list (zero-arg; excess argc
 * stays live — ArgumentCountError),
 * getrusage (zero-arg or typed long; soft-null mode stays live —
 * deprecate; excess argc stays live — ArgumentCountError),
 * json_last_error / json_last_error_msg / preg_last_error /
 * preg_last_error_msg / date_default_timezone_get / timezone_version_get /
 * stream_get_wrappers / stream_get_transports / stream_get_filters /
 * cli_get_process_title (zero-arg; excess argc stays live —
 * ArgumentCountError),
 * timezone_abbreviations_list / ob_list_handlers / date_get_last_errors /
 * http_get_last_response_headers / spl_autoload_functions / time /
 * error_reporting / ignore_user_abort / http_response_code / headers_sent
 * (zero-arg; setter / by-ref forms stay live; excess argc stays live —
 * ArgumentCountError),
 * timezone_identifiers_list (zero-arg or typed long group; soft-null group
 * stays live — deprecate; country-code form stays live — ValueError;
 * excess argc stays live — ArgumentCountError),
 * microtime / hrtime / gettimeofday (zero-arg or typed bool; soft-null bool
 * stays live — deprecate; excess argc stays live — ArgumentCountError),
 * getdate / localtime (zero-arg or typed timestamp; localtime optional typed
 * associative; soft-null stays live — deprecate; excess argc stays live —
 * ArgumentCountError),
 * idate (compile-time valid one-char format + optional typed timestamp;
 * soft-null / non-constant / unrecognized format stays live — deprecate /
 * warning; excess argc stays live — ArgumentCountError),
 * date / gmdate (typed format string + optional typed-or-null timestamp;
 * soft-null format stays live — deprecate; excess argc stays live —
 * ArgumentCountError),
 * mktime / gmmktime (1..6 typed numeric parts; hour required non-null;
 * optional null components OK; soft-null hour stays live — deprecate;
 * string / object / excess argc stay live — TypeError /
 * ArgumentCountError),
 * strtotime (typed datetime string + optional typed-or-null base
 * timestamp; soft-null datetime stays live — deprecate; excess argc
 * stays live — ArgumentCountError),
 * date_parse (exactly one typed datetime string; soft-null stays live —
 * deprecate; excess / zero argc stay live — ArgumentCountError),
 * date_parse_from_format (exactly two typed strings; soft-null stays
 * live — deprecate / TypeError; wrong argc stays live —
 * ArgumentCountError),
 * date_sun_info (exactly three typed numerics — timestamp / latitude /
 * longitude; soft-null / non-numeric / wrong argc stay live — TypeError /
 * ArgumentCountError),
 * timezone_name_from_abbr (typed abbr string + optional typed longs;
 * soft-null / excess argc stay live — deprecate / ArgumentCountError),
 * gregoriantojd / juliantojd / jewishtojd / frenchtojd (exactly three typed
 * numerics — month / day / year; soft-null / non-numeric / wrong argc stay
 * live — TypeError / ArgumentCountError),
 * cal_days_in_month (compile-time calendar id in [0, CAL_NUM_CALS) + two
 * typed numerics; runtime / invalid calendar stays live — ValueError;
 * soft-null / wrong argc stay live),
 * jdtogregorian / jdtojulian / jdtofrench (exactly one typed numeric —
 * julian day; soft-null / non-numeric / wrong argc stay live — TypeError /
 * ArgumentCountError),
 * jdmonthname (exactly two typed numerics — julian day / mode; soft-null /
 * wrong argc stay live),
 * jddayofweek (1..2 typed numerics — julian day + optional mode; soft-null /
 * wrong argc stay live),
 * cal_from_jd (typed julian day + compile-time calendar id in
 * [0, CAL_NUM_CALS); runtime / invalid calendar stays live — ValueError;
 * soft-null / wrong argc stay live),
 * cal_to_jd (compile-time calendar id in [0, CAL_NUM_CALS) + three typed
 * numerics; runtime / invalid calendar stays live — ValueError; soft-null /
 * wrong argc stay live),
 * cal_info (zero-arg or compile-time calendar id −1 or in [0, CAL_NUM_CALS);
 * runtime / invalid calendar stays live — ValueError; soft-null / excess
 * argc stay live),
 * easter_days / easter_date (compile-time year in the php-src ValueError
 * window + optional typed mode; zero-arg / soft-null year stay live —
 * current-year clock; runtime year stays live — ValueError),
 * jdtojewish (exactly one typed numeric; hebrew/flags forms stay live —
 * optional ValueError paths; soft-null / wrong argc stay live),
 * jdtounix (compile-time julian day in [UNIX_EPOCH_JD, max]; runtime /
 * out-of-range stay live — ValueError; soft-null / wrong argc stay live),
 * unixtojd (exactly one compile-time timestamp ≥ 0; zero-arg / soft-null
 * stay live — time()/deprecate; negative / runtime stay live — ValueError),
 * getrandmax / mt_getrandmax (zero-arg; excess argc stays live —
 * ArgumentCountError),
 * typed-array array_key_first / array_key_last / array_is_list (exactly one
 * typed hashtable / packed array / value-box hashtable; soft-null / non-array
 * / excess argc stay live — TypeError / ArgumentCountError),
 * typed-array array_keys / array_values / array_first / array_last (exactly
 * one typed array; filtered {@code array_keys} stays live; soft-null /
 * non-array / excess argc stay live — TypeError / ArgumentCountError),
 * typed-array array_reverse / array_change_key_case (typed array + optional
 * typed preserve_keys / case; soft-null optional stays live — deprecate;
 * soft-null / non-array haystack stay live — TypeError),
 * typed-array array_unique (typed array + optional typed flags; soft-null
 * flags stay live — deprecate; soft-null / non-array stay live — TypeError),
 * typed-array array_slice (typed array + typed offset + optional length
 * null-or-typed + optional typed preserve_keys; soft-null offset /
 * preserve_keys stay live — deprecate; soft-null / non-array stay live),
 * typed-array array_chunk (typed array + compile-time size ≥ 1 + optional
 * typed preserve_keys; non-constant / &lt;1 size stays live — ValueError;
 * soft-null optional stays live — deprecate),
 * typed-array array_sum / array_product (exactly one typed array; soft-null
 * / non-array / excess argc stay live — TypeError / ArgumentCountError),
 * typed-array array_merge / array_merge_recursive / array_replace /
 * array_replace_recursive (all args typed arrays; zero-arg merge OK;
 * zero-arg replace stays live — ArgumentCountError; soft-null / non-array
 * stay live — TypeError),
 * typed-array array_diff / array_intersect / array_diff_key /
 * array_intersect_key / array_diff_assoc / array_intersect_assoc (≥1 typed
 * arrays; zero-arg stays live — ArgumentCountError; soft-null / non-array
 * stay live — TypeError; callback u* forms stay live),
 * typed-array in_array / array_search (typed haystack + any needle + optional
 * typed strict; soft-null / non-array haystack stay live — TypeError;
 * soft-null strict stays live — deprecate; argc &lt; 2 / excess stay live),
 * typed-array array_pad (typed array + compile-time |length| ≤ 1048576 + any
 * value; non-constant / PHP_INT_MIN / oversized length stay live — ValueError;
 * soft-null array/length stay live; 4-arg pad_type stays live),
 * array_fill (typed start + compile-time count in [0, 1048576] + any value;
 * soft-null / non-constant / negative / oversized count stay live),
 * array_fill_keys (typed keys array + any value; soft-null / non-array keys
 * stay live — TypeError),
 * array_column (typed array + null-or-typed str/int column_key + optional
 * null-or-typed index_key; soft-null / non-array / non-scalar keys stay live),
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
 * {@code getmyuid}/{@code getmygid}/{@code getmyinode}/{@code getlastmod}/
 * {@code get_current_user} stay live ({@code ArgumentCountError}). Soft-null
 * {@code memory_get_usage}/{@code memory_get_peak_usage} bool stays live
 * (deprecate / TypeError); excess argc stays live; non-zero-arg
 * {@code php_ini_loaded_file}/{@code php_ini_scanned_files}/
 * {@code gc_enabled} stay live ({@code ArgumentCountError}). Non-zero-arg
 * {@code sys_get_temp_dir}/{@code getcwd}/{@code get_include_path}/
 * {@code ob_get_level}/{@code connection_status}/{@code connection_aborted}/
 * {@code session_status}/{@code localeconv}/{@code gc_status} stay live
 * ({@code ArgumentCountError}). Soft-null {@code getrusage} mode stays live
 * (deprecate); non-zero-arg {@code gethostname}/{@code error_get_last}/
 * {@code hash_algos}/{@code hash_hmac_algos}/{@code ob_get_contents}/
 * {@code ob_get_length}/{@code headers_list} and excess-arg {@code getrusage}
 * stay live ({@code ArgumentCountError}). Non-zero-arg
 * {@code json_last_error}/{@code json_last_error_msg}/{@code preg_last_error}/
 * {@code preg_last_error_msg}/{@code date_default_timezone_get}/
 * {@code timezone_version_get}/{@code stream_get_wrappers}/
 * {@code stream_get_transports}/{@code stream_get_filters}/
 * {@code cli_get_process_title} stay live ({@code ArgumentCountError}).
 * Non-zero-arg {@code timezone_abbreviations_list}/{@code ob_list_handlers}/
 * {@code date_get_last_errors}/{@code http_get_last_response_headers}/
 * {@code spl_autoload_functions}/{@code time} stay live
 * ({@code ArgumentCountError}). Soft-null {@code timezone_identifiers_list}
 * group stays live (deprecate); country-code / excess-arg forms stay live.
 * Non-zero-arg {@code error_reporting}/{@code ignore_user_abort}/
 * {@code http_response_code}/{@code headers_sent} stay live (setter /
 * by-ref side effects). Soft-null {@code microtime}/{@code hrtime}/
 * {@code gettimeofday} bool stays live (deprecate); excess argc stays live
 * ({@code ArgumentCountError}).
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
        if (self::tryElidePureHashNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureHashHmacNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureSprintfNoSideEffect($toCall, $callArgs)) {
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
        if (self::tryElidePureMemoryIniRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureEnvPathRequestRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureHostErrorHashObRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureJsonPregTzStreamCliRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureDateObHttpSplTimeGetterRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureClockGetterRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCivilDateGetterRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureDateFormatRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureMktimeRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureStrtotimeRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureDateParseRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureDateSunInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureTimezoneNameFromAbbrNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCalendarToJdNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCalDaysInMonthNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCalendarFromJdNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureJdMonthNameNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureJdDayOfWeekNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCalFromJdNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCalToJdNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCalInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureEasterNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureJdtojewishNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureJdtounixNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureUnixtojdNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureRandmaxRuntimeInfoNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureArrayKeyEdgeNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureArrayCopyNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureArrayTransformNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureArrayMergeDiffNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureArrayLookupNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureArrayConstructNoSideEffect($toCall, $callArgs)) {
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
     * {@code getmyuid}/{@code getmygid}/{@code getmyinode}/{@code getlastmod}/
     * {@code get_current_user} — php-src {@code info.c}/
     * {@code basic_functions.c}. Pure process / script identity reads.
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
     * Discarded {@code memory_get_usage}/{@code memory_get_peak_usage}/
     * {@code php_ini_loaded_file}/{@code php_ini_scanned_files}/
     * {@code gc_enabled} — php-src alloc / ini / GC introspection. Soft-null
     * bool stays live (deprecate / TypeError). Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMemoryIniRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $nameLc = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureMemoryIniRuntimeInfoBuiltin($nameLc)) {
            return false;
        }

        return self::memoryIniRuntimeInfoArgsAllowDiscardedElision($nameLc, $callArgs);
    }

    /**
     * Discarded {@code sys_get_temp_dir}/{@code getcwd}/{@code get_include_path}/
     * {@code ob_get_level}/{@code connection_status}/{@code connection_aborted}/
     * {@code session_status}/{@code localeconv}/{@code gc_status} — php-src
     * file/dir/basic_functions/output/session/locale/GC introspection reads.
     * Excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureEnvPathRequestRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureEnvPathRequestRuntimeInfoBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::envPathRequestRuntimeInfoArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code gethostname}/{@code error_get_last}/{@code getrusage}/
     * {@code hash_algos}/{@code hash_hmac_algos}/{@code ob_get_contents}/
     * {@code ob_get_length}/{@code headers_list} — php-src host / last-error /
     * rusage / hash-algo / OB / pending-header introspection reads. Soft-null
     * {@code getrusage} mode stays live (deprecate). Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureHostErrorHashObRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $nameLc = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureHostErrorHashObRuntimeInfoBuiltin($nameLc)) {
            return false;
        }

        return self::hostErrorHashObRuntimeInfoArgsAllowDiscardedElision($nameLc, $callArgs);
    }

    /**
     * Discarded {@code json_last_error}/{@code json_last_error_msg}/
     * {@code preg_last_error}/{@code preg_last_error_msg}/
     * {@code date_default_timezone_get}/{@code timezone_version_get}/
     * {@code stream_get_wrappers}/{@code stream_get_transports}/
     * {@code stream_get_filters}/{@code cli_get_process_title} — php-src
     * JSON/PCRE last-error, date default TZ / tzdata version, stream registry,
     * CLI title introspection reads. Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJsonPregTzStreamCliRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureJsonPregTzStreamCliRuntimeInfoBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::jsonPregTzStreamCliRuntimeInfoArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code timezone_abbreviations_list}/
     * {@code timezone_identifiers_list}/{@code ob_list_handlers}/
     * {@code date_get_last_errors}/{@code http_get_last_response_headers}/
     * {@code spl_autoload_functions}/{@code time}/{@code error_reporting}/
     * {@code ignore_user_abort}/{@code http_response_code}/{@code headers_sent}
     * — php-src date/OB/HTTP/SPL/time introspection getters. Setter /
     * by-ref forms stay live. Soft-null {@code timezone_identifiers_list}
     * group stays live (deprecate). Country-code form stays live
     * ({@code ValueError}). Excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDateObHttpSplTimeGetterRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $nameLc = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureDateObHttpSplTimeGetterRuntimeInfoBuiltin($nameLc)) {
            return false;
        }

        return self::dateObHttpSplTimeGetterRuntimeInfoArgsAllowDiscardedElision($nameLc, $callArgs);
    }

    /**
     * Discarded {@code microtime}/{@code hrtime}/{@code gettimeofday} — php-src
     * {@code ext/standard/microtime.c}/{@code hrtime.c}. Clock reads with no
     * user handlers. Soft-null bool stays live (deprecate). Excess argc stays
     * live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureClockGetterRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureClockGetterRuntimeInfoBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return self::clockGetterRuntimeInfoArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Discarded {@code getdate}/{@code localtime}/{@code idate} — php-src
     * {@code ext/date/php_date.c}/{@code ext/standard/datetime.c}. Civil date
     * reads with no user handlers. Soft-null timestamp / format stays live
     * (deprecate). {@code idate} non-constant / unrecognized format stays live
     * (warning). Excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCivilDateGetterRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $nameLc = strtolower($toCall->getName());
        if (!NoThrowCallElision::isPureCivilDateGetterRuntimeInfoBuiltin($nameLc)) {
            return false;
        }

        return self::civilDateGetterRuntimeInfoArgsAllowDiscardedElision($nameLc, $callArgs);
    }

    /**
     * Discarded {@code date}/{@code gmdate} — php-src {@code ext/date/php_date.c}.
     * Typed format string; optional typed-or-null timestamp. Soft-null format
     * stays live (deprecate). Excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDateFormatRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('date' !== $name && 'gmdate' !== $name) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[2])) {
            return false;
        }
        if (!self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (!$callArgs[1] instanceof Variable) {
            return false;
        }
        // Z_PARAM_LONG_OR_NULL — explicit null means "now"; soft-null format already excluded.
        if ($callArgs[1]->isNullConstant || Variable::TYPE_NULL === $callArgs[1]->type) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * Discarded {@code mktime}/{@code gmmktime} — php-src {@code ext/date/php_date.c}.
     * 1..6 typed numeric civil parts; hour required non-null; optional null
     * components OK ({@code ?int}). Soft-null hour / string / object stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureMktimeRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('mktime' !== $name && 'gmmktime' !== $name) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 6) {
            return false;
        }
        foreach ($callArgs as $i => $arg) {
            if (!$arg instanceof Variable) {
                return false;
            }
            if (0 === $i) {
                // Required hour — soft-null deprecates / TypeErrors under strict.
                if (!self::mktimeNumericArgAllowsDiscardedElision($arg)) {
                    return false;
                }
                continue;
            }
            // Optional ?int — explicit null OK; soft-null / string / object stay live.
            if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
                continue;
            }
            if (!self::mktimeNumericArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code strtotime} — php-src {@code ext/date/php_date.c}.
     * Typed datetime string; optional typed-or-null base timestamp. Soft-null
     * datetime stays live (deprecate). Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureStrtotimeRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('strtotime' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[2])) {
            return false;
        }
        if (!self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return true;
        }
        if (!$callArgs[1] instanceof Variable) {
            return false;
        }
        // Z_PARAM_LONG_OR_NULL — explicit null means "now".
        if ($callArgs[1]->isNullConstant || Variable::TYPE_NULL === $callArgs[1]->type) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * Discarded {@code date_parse}/{@code date_parse_from_format} — php-src
     * {@code ext/date/php_date.c}. Typed string args only. Soft-null stays live
     * (deprecate / TypeError). Wrong argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDateParseRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('date_parse' === $name) {
            if (1 !== \count($callArgs) || !$callArgs[0] instanceof Variable) {
                return false;
            }

            return self::stringArgAllowsDiscardedElision($callArgs[0]);
        }
        if ('date_parse_from_format' !== $name) {
            return false;
        }
        if (2 !== \count($callArgs)
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }

        return self::stringArgAllowsDiscardedElision($callArgs[0])
            && self::stringArgAllowsDiscardedElision($callArgs[1]);
    }

    /**
     * Discarded {@code date_sun_info} — php-src {@code ext/date/php_date.c}.
     * Exactly three typed numerics (timestamp / latitude / longitude). Soft-null
     * / non-numeric stay live ({@code TypeError} / deprecate). Wrong argc stays
     * live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureDateSunInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('date_sun_info' !== strtolower($toCall->getName())) {
            return false;
        }
        if (3 !== \count($callArgs)) {
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
     * Discarded {@code timezone_name_from_abbr} — php-src {@code ext/date/php_date.c}.
     * Typed abbr string + optional typed {@code gmtoffset}/{@code isdst} longs.
     * Soft-null stays live (deprecate). Excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureTimezoneNameFromAbbrNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('timezone_name_from_abbr' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 3) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !self::stringArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        for ($i = 1; $i < $argc; ++$i) {
            if (
                !$callArgs[$i] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[$i])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code gregoriantojd}/{@code juliantojd}/{@code jewishtojd}/
     * {@code frenchtojd} — php-src {@code ext/calendar/calendar.c}. Exactly
     * three typed numerics (month / day / year). Soft-null / non-numeric stay
     * live ({@code TypeError} / deprecate). Wrong argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalendarToJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        switch (strtolower($toCall->getName())) {
            case 'gregoriantojd':
            case 'juliantojd':
            case 'jewishtojd':
            case 'frenchtojd':
                break;
            default:
                return false;
        }
        if (3 !== \count($callArgs)) {
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
     * Discarded {@code cal_days_in_month} — php-src {@code ext/calendar/calendar.c}.
     * Compile-time calendar id in {@code [0, CAL_NUM_CALS)} (php-src
     * {@code CAL_NUM_CALS == 4}) plus two typed numerics (month / year).
     * Runtime / invalid calendar stays live ({@code ValueError}). Soft-null /
     * wrong argc stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalDaysInMonthNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_days_in_month' !== strtolower($toCall->getName())) {
            return false;
        }
        if (3 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
            || $callArgs[0]->compileTimeLong < 0
            || $callArgs[0]->compileTimeLong >= 4
        ) {
            return false;
        }
        for ($i = 1; $i < 3; ++$i) {
            if (
                !$callArgs[$i] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[$i])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code jdtogregorian}/{@code jdtojulian}/{@code jdtofrench} —
     * php-src {@code ext/calendar/calendar.c}. Exactly one typed numeric
     * (julian day). Soft-null / non-numeric stay live ({@code TypeError} /
     * deprecate). Wrong argc stays live ({@code ArgumentCountError}).
     * {@code jdtojewish}/{@code jdtounix} have dedicated handlers below
     * (hebrew/flags and unix-range {@code ValueError} paths).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalendarFromJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        switch (strtolower($toCall->getName())) {
            case 'jdtogregorian':
            case 'jdtojulian':
            case 'jdtofrench':
                break;
            default:
                return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }

        return $callArgs[0] instanceof Variable
            && self::mathArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * Discarded {@code jdmonthname} — php-src {@code ext/calendar/calendar.c}.
     * Exactly two typed numerics (julian day / mode). Soft-null / wrong argc
     * stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdMonthNameNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jdmonthname' !== strtolower($toCall->getName())) {
            return false;
        }
        if (2 !== \count($callArgs)) {
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
     * Discarded {@code jddayofweek} — php-src {@code ext/calendar/dow.c}.
     * One or two typed numerics (julian day + optional mode). Soft-null /
     * wrong argc stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdDayOfWeekNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jddayofweek' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 2) {
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
     * Discarded {@code cal_from_jd} — php-src {@code ext/calendar/calendar.c}.
     * Typed julian day + compile-time calendar id in {@code [0, CAL_NUM_CALS)}.
     * Runtime / invalid calendar stays live ({@code ValueError}). Soft-null /
     * wrong argc stay live (deprecate / {@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalFromJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_from_jd' !== strtolower($toCall->getName())) {
            return false;
        }
        if (2 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || !self::mathArgAllowsDiscardedElision($callArgs[0])
        ) {
            return false;
        }
        if (
            !$callArgs[1] instanceof Variable
            || null === $callArgs[1]->compileTimeLong
            || $callArgs[1]->compileTimeLong < 0
            || $callArgs[1]->compileTimeLong >= 4
        ) {
            return false;
        }

        return true;
    }

    /**
     * Discarded {@code cal_to_jd} — php-src {@code ext/calendar/calendar.c}.
     * Compile-time calendar id in {@code [0, CAL_NUM_CALS)} plus three typed
     * numerics (month / day / year). Runtime / invalid calendar stays live
     * ({@code ValueError}). Soft-null / wrong argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalToJdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_to_jd' !== strtolower($toCall->getName())) {
            return false;
        }
        if (4 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
            || $callArgs[0]->compileTimeLong < 0
            || $callArgs[0]->compileTimeLong >= 4
        ) {
            return false;
        }
        for ($i = 1; $i < 4; ++$i) {
            if (
                !$callArgs[$i] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[$i])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code cal_info} — php-src {@code ext/calendar/calendar.c}.
     * Zero-arg (all calendars) or compile-time calendar id {@code -1} /
     * {@code [0, CAL_NUM_CALS)}. Runtime / invalid calendar stays live
     * ({@code ValueError}). Soft-null / excess argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureCalInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('cal_info' !== strtolower($toCall->getName())) {
            return false;
        }
        $argc = \count($callArgs);
        if (0 === $argc) {
            return true;
        }
        if (1 !== $argc) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }
        $cal = $callArgs[0]->compileTimeLong;

        return -1 === $cal || ($cal >= 0 && $cal < 4);
    }

    /**
     * Discarded {@code easter_days}/{@code easter_date} — php-src
     * {@code ext/calendar/easter.c}. Compile-time year inside the php-src
     * {@code ValueError} window plus optional typed mode. Zero-arg /
     * soft-null year stay live (current-year clock). Runtime year stays live
     * ({@code ValueError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureEasterNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('easter_days' !== $name && 'easter_date' !== $name) {
            return false;
        }
        $argc = \count($callArgs);
        if ($argc < 1 || $argc > 2) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }
        $year = $callArgs[0]->compileTimeLong;
        $maxYear = intdiv(\PHP_INT_MAX, 5) * 4;
        if ($year <= 0 || $year > $maxYear) {
            return false;
        }
        if ('easter_date' === $name) {
            if (\PHP_INT_SIZE >= 8) {
                if ($year < 1970 || $year > 2000000000) {
                    return false;
                }
            } elseif ($year < 1970 || $year > 2037) {
                return false;
            }
        }
        if (2 === $argc) {
            if (
                !$callArgs[1] instanceof Variable
                || !self::mathArgAllowsDiscardedElision($callArgs[1])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code jdtojewish} — php-src {@code ext/calendar/calendar.c}.
     * Exactly one typed numeric (hebrew defaults false). Hebrew / flags forms
     * stay live (optional formatting {@code ValueError} paths). Soft-null /
     * wrong argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdtojewishNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jdtojewish' !== strtolower($toCall->getName())) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }

        return $callArgs[0] instanceof Variable
            && self::mathArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * Discarded {@code jdtounix} — php-src {@code ext/calendar/cal_unix.c}.
     * Compile-time julian day in {@code [UNIX_EPOCH_JD, UNIX_EPOCH_JD +
     * PHP_INT_MAX/86400]}. Runtime / out-of-range stay live ({@code ValueError}).
     * Soft-null / wrong argc stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureJdtounixNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('jdtounix' !== strtolower($toCall->getName())) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }
        $jd = $callArgs[0]->compileTimeLong;
        $epochJd = 2440588;
        $maxJd = $epochJd + intdiv(\PHP_INT_MAX, 86400);

        return $jd >= $epochJd && $jd <= $maxJd;
    }

    /**
     * Discarded {@code unixtojd} — php-src {@code ext/calendar/cal_unix.c}.
     * Exactly one compile-time timestamp ≥ 0 (oversized timestamps return
     * false — discarded). Zero-arg / soft-null stay live ({@code time()} /
     * deprecate). Negative / runtime stay live ({@code ValueError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureUnixtojdNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('unixtojd' !== strtolower($toCall->getName())) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }
        if (
            !$callArgs[0] instanceof Variable
            || null === $callArgs[0]->compileTimeLong
        ) {
            return false;
        }

        return $callArgs[0]->compileTimeLong >= 0;
    }

    /**
     * Discarded {@code getrandmax}/{@code mt_getrandmax} — php-src
     * {@code ext/random/random.c}. Constant MT upper bound. Excess argc stays
     * live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureRandmaxRuntimeInfoNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if (!NoThrowCallElision::isPureRandmaxRuntimeInfoBuiltin(strtolower($toCall->getName()))) {
            return false;
        }

        return [] === $callArgs;
    }

    /**
     * mktime/gmmktime civil parts — typed long/double/bool / compile-time number.
     * Numeric string literals stay live (our VM TypeErrors; Zend Z_PARAM_LONG
     * coerces — keep discarded-elision conservative on strings).
     */
    private static function mktimeNumericArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        if (null !== $arg->compileTimeLong || null !== $arg->compileTimeFloat) {
            return true;
        }

        return Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type;
    }

    /**
     * Discarded {@code array_key_first}/{@code array_key_last}/
     * {@code array_is_list} on a typed hashtable / packed array / value-box
     * hashtable — php-src {@code ext/standard/array.c}. Soft-null / non-array
     * stay live ({@code TypeError}); excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayKeyEdgeNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (
            'array_key_first' !== $name
            && 'array_key_last' !== $name
            && 'array_is_list' !== $name
        ) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }

        return self::isTypedArrayArg($callArgs[0]);
    }

    /**
     * Discarded {@code array_keys}/{@code array_values}/{@code array_first}/
     * {@code array_last}/{@code array_reverse}/{@code array_change_key_case}
     * on a typed hashtable / packed array / value-box hashtable — php-src
     * {@code ext/standard/array.c}. Filtered {@code array_keys} (search /
     * strict) stays live. Soft-null / non-array haystacks stay live
     * ({@code TypeError}); soft-null optional flags stay live (deprecate);
     * excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayCopyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (
            'array_keys' !== $name
            && 'array_values' !== $name
            && 'array_first' !== $name
            && 'array_last' !== $name
            && 'array_reverse' !== $name
            && 'array_change_key_case' !== $name
        ) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[0])) {
            return false;
        }

        switch ($name) {
            case 'array_keys':
            case 'array_values':
            case 'array_first':
            case 'array_last':
                // One-arg only — filtered array_keys / excess argc stay live.
                return 1 === \count($callArgs);
            case 'array_reverse':
            case 'array_change_key_case':
                if (isset($callArgs[2])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code array_unique}/{@code array_slice}/{@code array_chunk}/
     * {@code array_sum}/{@code array_product} on typed arrays — php-src
     * {@code ext/standard/array.c}. Soft-null / non-array haystacks stay live
     * ({@code TypeError}). {@code array_chunk} requires a compile-time size
     * ≥ 1 ({@code ValueError} otherwise). Soft-null optional flags stay live
     * (deprecate). Excess argc stays live ({@code ArgumentCountError}).
     * {@code array_flip} is not elided (non-int/string values → {@code ValueError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayTransformNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (
            'array_unique' !== $name
            && 'array_slice' !== $name
            && 'array_chunk' !== $name
            && 'array_sum' !== $name
            && 'array_product' !== $name
        ) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[0])) {
            return false;
        }

        switch ($name) {
            case 'array_sum':
            case 'array_product':
                return 1 === \count($callArgs);
            case 'array_unique':
                if (isset($callArgs[2])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            case 'array_chunk':
                if (!isset($callArgs[1]) || !$callArgs[1] instanceof Variable) {
                    return false;
                }
                if (isset($callArgs[3])) {
                    return false;
                }
                // ValueError when size < 1 — only elide proven positive sizes.
                $size = $callArgs[1]->compileTimeLong;
                if (null === $size || $size < 1) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[2]);
            case 'array_slice':
                if (!isset($callArgs[1]) || !$callArgs[1] instanceof Variable) {
                    return false;
                }
                if (isset($callArgs[4])) {
                    return false;
                }
                if (!self::mathArgAllowsDiscardedElision($callArgs[1])) {
                    return false;
                }
                if (isset($callArgs[2])) {
                    if (!$callArgs[2] instanceof Variable) {
                        return false;
                    }
                    // null length means "to end" (not a soft-null deprecate).
                    if (
                        !$callArgs[2]->isNullConstant
                        && Variable::TYPE_NULL !== $callArgs[2]->type
                        && !self::mathArgAllowsDiscardedElision($callArgs[2])
                    ) {
                        return false;
                    }
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[3]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code array_merge}/{@code array_merge_recursive}/
     * {@code array_replace}/{@code array_replace_recursive}/
     * {@code array_diff}/{@code array_intersect}/{@code array_diff_key}/
     * {@code array_intersect_key}/{@code array_diff_assoc}/
     * {@code array_intersect_assoc} on typed arrays — php-src
     * {@code ext/standard/array.c}. Soft-null / non-array args stay live
     * ({@code TypeError}). Zero-arg {@code array_replace*} /
     * {@code array_diff*} / {@code array_intersect*} stay live
     * ({@code ArgumentCountError}). Callback {@code array_u*} forms stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayMergeDiffNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        $isMergeFamily = 'array_merge' === $name || 'array_merge_recursive' === $name;
        $isReplaceFamily = 'array_replace' === $name || 'array_replace_recursive' === $name;
        $isDiffFamily =
            'array_diff' === $name
            || 'array_intersect' === $name
            || 'array_diff_key' === $name
            || 'array_intersect_key' === $name
            || 'array_diff_assoc' === $name
            || 'array_intersect_assoc' === $name;
        if (!$isMergeFamily && !$isReplaceFamily && !$isDiffFamily) {
            return false;
        }
        // Zero-arg merge returns [] (php-src); replace/diff/intersect throw.
        if ([] === $callArgs) {
            return $isMergeFamily;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::isTypedArrayArg($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code in_array}/{@code array_search} on a typed haystack —
     * php-src {@code ext/standard/array.c}. Soft-null / non-array haystacks
     * stay live ({@code TypeError}). Soft-null {@code $strict} stays live
     * (deprecate). Needle is {@code Z_PARAM_ZVAL} (null / object / value-box OK).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayLookupNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('in_array' !== $name && 'array_search' !== $name) {
            return false;
        }
        // needle + haystack required; optional strict; excess argc → ArgumentCountError.
        if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[3])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[1])) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return true;
        }

        return $callArgs[2] instanceof Variable
            && self::mathArgAllowsDiscardedElision($callArgs[2]);
    }

    /**
     * Discarded {@code array_pad}/{@code array_fill}/{@code array_fill_keys}/
     * {@code array_column} — php-src {@code ext/standard/array.c}. Soft-null /
     * non-array inputs stay live ({@code TypeError} / deprecate).
     * {@code array_pad} / {@code array_fill} require compile-time sizes that
     * cannot trip Zend {@code ValueError} guards.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayConstructNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        switch ($name) {
            case 'array_pad':
                // 3-arg only — 4-arg pad_type / ArrayPadType stays live.
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }
                if (!self::isTypedArrayArg($callArgs[0])) {
                    return false;
                }
                $length = $callArgs[1]->compileTimeLong;
                // VmArray::rejectOversizedPad: PHP_INT_MIN or |len|-inputSize > 1M.
                if (null === $length || \PHP_INT_MIN === $length) {
                    return false;
                }
                if (abs($length) > 1048576) {
                    return false;
                }

                return true;
            case 'array_fill':
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }
                if (!self::mathArgAllowsDiscardedElision($callArgs[0])) {
                    return false;
                }
                $count = $callArgs[1]->compileTimeLong;
                // php-src php_array_fill: count < 0 or count > 1048576 → ValueError.
                if (null === $count || $count < 0 || $count > 1048576) {
                    return false;
                }

                return true;
            case 'array_fill_keys':
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
                    return false;
                }
                if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
                    return false;
                }

                return self::isTypedArrayArg($callArgs[0]);
            case 'array_column':
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[3])) {
                    return false;
                }
                if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
                    return false;
                }
                if (!self::isTypedArrayArg($callArgs[0])) {
                    return false;
                }
                if (!self::arrayColumnKeyAllowsDiscardedElision($callArgs[1])) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::arrayColumnKeyAllowsDiscardedElision($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * {@code array_column} column_key / index_key: null, typed string, or typed
     * long — objects / generic value-boxes stay live ({@code TypeError}).
     */
    private static function arrayColumnKeyAllowsDiscardedElision(Variable $arg): bool
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
     * {@code getmypid}/{@code getmyuid}/{@code getmygid}/{@code getmyinode}/
     * {@code getlastmod}/{@code get_current_user}: arity 0.
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
            case 'getmyinode':
            case 'getlastmod':
            case 'get_current_user':
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
     * {@code php_ini_loaded_file}/{@code php_ini_scanned_files}/{@code gc_enabled}:
     * arity 0. {@code memory_get_usage}/{@code memory_get_peak_usage}: arity 0
     * or typed bool (soft-null stays live — deprecate / TypeError).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function memoryIniRuntimeInfoArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        switch ($nameLc) {
            case 'php_ini_loaded_file':
            case 'php_ini_scanned_files':
            case 'gc_enabled':
                return [] === $callArgs;
            case 'memory_get_usage':
            case 'memory_get_peak_usage':
                return self::definedTableRuntimeInfoArgsAllowDiscardedElision($callArgs);
            default:
                return false;
        }
    }

    /**
     * Exactly zero arguments — peer
     * {@see NoThrowCallElision::envPathRequestRuntimeInfoArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function envPathRequestRuntimeInfoArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::envPathRequestRuntimeInfoArgsCannotThrow($callArgs);
    }

    /**
     * {@code gethostname}/{@code error_get_last}/{@code hash_algos}/
     * {@code hash_hmac_algos}/{@code ob_get_contents}/{@code ob_get_length}/
     * {@code headers_list}: arity 0. {@code getrusage}: arity 0 or typed /
     * literal numeric mode (soft-null stays live — deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function hostErrorHashObRuntimeInfoArgsAllowDiscardedElision(string $nameLc, array $callArgs): bool
    {
        switch ($nameLc) {
            case 'gethostname':
            case 'error_get_last':
            case 'hash_algos':
            case 'hash_hmac_algos':
            case 'ob_get_contents':
            case 'ob_get_length':
            case 'headers_list':
                return [] === $callArgs;
            case 'getrusage':
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

                return self::mathArgAllowsDiscardedElision($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Exactly zero arguments — peer
     * {@see NoThrowCallElision::jsonPregTzStreamCliRuntimeInfoArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function jsonPregTzStreamCliRuntimeInfoArgsAllowDiscardedElision(array $callArgs): bool
    {
        return NoThrowCallElision::jsonPregTzStreamCliRuntimeInfoArgsCannotThrow($callArgs);
    }

    /**
     * {@code timezone_abbreviations_list}/{@code ob_list_handlers}/
     * {@code date_get_last_errors}/{@code http_get_last_response_headers}/
     * {@code spl_autoload_functions}/{@code time}/{@code error_reporting}/
     * {@code ignore_user_abort}/{@code http_response_code}/{@code headers_sent}:
     * arity 0. {@code timezone_identifiers_list}: arity 0 or typed long group
     * (soft-null stays live — deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function dateObHttpSplTimeGetterRuntimeInfoArgsAllowDiscardedElision(
        string $nameLc,
        array $callArgs
    ): bool {
        switch ($nameLc) {
            case 'timezone_abbreviations_list':
            case 'ob_list_handlers':
            case 'date_get_last_errors':
            case 'http_get_last_response_headers':
            case 'spl_autoload_functions':
            case 'time':
            case 'error_reporting':
            case 'ignore_user_abort':
            case 'http_response_code':
            case 'headers_sent':
                return [] === $callArgs;
            case 'timezone_identifiers_list':
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

                return self::mathArgAllowsDiscardedElision($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Exactly zero arguments, or one typed bool/numeric flag. Soft-null stays
     * live (deprecate) — unlike {@see NoThrowCallElision::clockGetterRuntimeInfoArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function clockGetterRuntimeInfoArgsAllowDiscardedElision(array $callArgs): bool
    {
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

        return self::mathArgAllowsDiscardedElision($callArgs[0]);
    }

    /**
     * Soft-null timestamp / format stays live (deprecate / warning). {@code idate}
     * requires a compile-time valid one-char format token.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function civilDateGetterRuntimeInfoArgsAllowDiscardedElision(
        string $nameLc,
        array $callArgs
    ): bool {
        switch ($nameLc) {
            case 'getdate':
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

                return self::mathArgAllowsDiscardedElision($callArgs[0]);
            case 'localtime':
                if ([] === $callArgs) {
                    return true;
                }
                if (
                    !isset($callArgs[0])
                    || !$callArgs[0] instanceof Variable
                    || isset($callArgs[2])
                ) {
                    return false;
                }
                if (!self::mathArgAllowsDiscardedElision($callArgs[0])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            case 'idate':
                if (
                    !isset($callArgs[0])
                    || !$callArgs[0] instanceof Variable
                    || isset($callArgs[2])
                ) {
                    return false;
                }
                $fmt = JitStringArg::compileTimeLiteral($callArgs[0]);
                if (null === $fmt || !NoThrowCallElision::isValidIdateFormatLiteral($fmt)) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
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
     * Typed hashtable, packed native array, or value-box proven to hold a
     * hashtable — not Countable / generic value-box.
     */
    private static function isTypedArrayArg(Variable $arg): bool
    {
        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            return true;
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if ($arg->compileTimeEmptyArrayLiteral) {
            return true;
        }

        // Locals like {@code $a = [1,2,3]} lower as TYPE_VALUE with
        // {@see Variable::$valueBoxHashtable} (#36386 array_key_first elision).
        return Variable::TYPE_VALUE === $arg->type && $arg->valueBoxHashtable;
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
