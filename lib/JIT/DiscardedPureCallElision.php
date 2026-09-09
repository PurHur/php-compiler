<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\ext\standard\VmRoundMode;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Variable as VmVariable;

require_once __DIR__.'/DiscardedPureCallElisionStringOps.php';
require_once __DIR__.'/DiscardedPureCallElisionArrayOps.php';
require_once __DIR__.'/DiscardedPureCallElisionDateCalOps.php';
require_once __DIR__.'/DiscardedPureCallElisionFormatAnalyzeOps.php';
require_once __DIR__.'/DiscardedPureCallElisionMathAndHashOps.php';
require_once __DIR__.'/DiscardedPureCallElisionRuntimeInfoOps.php';
require_once __DIR__.'/DiscardedPureCallElisionNativeLongFolds.php';
require_once __DIR__.'/DiscardedPureCallElisionIntrospectOps.php';

/**
 * Elide discarded calls to compile-time-pure builtins (#23483 / #36386 call-overhead).
 * String html/slice/pad/replace/implode eliders live in {@see DiscardedPureCallElisionStringOps} (#36387).
 * Array key/copy/transform/merge/lookup/construct/combine/range eliders live in
 * {@see DiscardedPureCallElisionArrayOps} (#36387).
 * Date/calendar eliders live in {@see DiscardedPureCallElisionDateCalOps} (#36387).
 * sprintf/pathinfo/parse_url/count_chars/str_word_count/strip_tags/
 * get_html_translation_table/version_compare eliders live in
 * {@see DiscardedPureCallElisionFormatAnalyzeOps} (#36387).
 * Math / scalar-cast / inet / hash eliders live in
 * {@see DiscardedPureCallElisionMathAndHashOps} (#36387).
 * Runtime-info / process / clock / civil-date / randmax eliders live in
 * {@see DiscardedPureCallElisionRuntimeInfoOps} (#36387).
 * Native-long/intdiv/bitwise/pow fold helpers live in {@see DiscardedPureCallElisionNativeLongFolds} (#36403).
 * Exists / class / object introspect eliders live in
 * {@see DiscardedPureCallElisionIntrospectOps} (#36387).
 *
 * php-src: ZPP may still run user-visible coercions; here we only fold cases that are
 * side-effect-free (literal / typed-string strlen/ord/strtolower/ucwords/bin2hex/
 * urlencode/str_rot13/quotemeta/md5/crc32/base64_encode/soundex/…, typed
 * substr/str_repeat/strcmp/strpos/strstr/str_contains/str_starts_with/
 * str_ends_with/levenshtein/…, typed str_pad/chunk_split/wordwrap/str_split/
 * explode/str_getcsv, typed str_replace/str_ireplace/substr_replace/strtr
 * (string forms), typed implode/join (typed array pieces + typed string
 * separator; array-first two-arg stays live), typed
 * addcslashes/stripcslashes/strpbrk,
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
 * array_combine (equal-length non-empty {@code compileTimeArray}/
 * {@code compileTimeAssoc} packs only; empty literals / in-progress empty
 * packs / runtime typed arrays / {@code array} params stay live —
 * {@code compileTimeEmptyArrayLiteral} is also set on RECV slots so it is
 * not a length proof; ValueError on length mismatch),
 * range (2-arg typed numerics — default step ±1 cannot ValueError; 3-arg
 * only when all three are compile-time longs that pass php-src zero /
 * increasing-negative / oversized step checks; soft-null / runtime step /
 * char-string endpoints stay live),
 * count_chars (typed string + optional compile-time mode in [0,4]; soft-null /
 * runtime mode stay live — ValueError / deprecate),
 * str_word_count (typed string + optional compile-time format in [0,2] +
 * optional typed chars string; soft-null / runtime format stay live —
 * ValueError / deprecate),
 * strip_tags (typed string + optional typed string / array / null
 * allowable_tags; soft-null subject stays live — deprecate; object /
 * excess argc stay live — TypeError / ArgumentCountError),
 * get_html_translation_table (zero-arg or typed numeric / named int-constant
 * table/flags + optional typed encoding string; soft-null table/flags/encoding
 * stay live — deprecate; excess argc stays live — ArgumentCountError),
 * zero-arg pi, type.c predicates + gettype/get_debug_type, ctype.c
 * classifiers on typed/literal strings, typed-array count/sizeof, math.c
 * incl. pow/fpow/fdiv/nextafter on already-numeric args, round with optional
 * precision/mode (argc 1..3; mode must be compile-time {@code PHP_ROUND_*} when
 * {@see CompilerVersion::supportsRoundingModeEnum} else typed numeric —
 * invalid / soft-null mode stay live for {@code ValueError} / deprecate),
 * str_increment / str_decrement (compile-time ASCII-alphanumeric string
 * proven not to {@code ValueError}; soft-null / runtime typed / empty /
 * non-alphanumeric / out-of-range decrement stay live),
 * empty void user functions).
 * Soft-null strlen / ord / chr / math / string / ctype / inet coercions are
 * NOT elided — they emit deprecations (PHP 8.1+). Countable objects stay live
 * (user {@code count()} handlers). {@code intdiv} elides only when both args
 * are already-numeric and the divisor is a compile-time long ≠ 0; divisor
 * {@code -1} additionally requires a compile-time dividend ≠ {@code PHP_INT_MIN}
 * ({@code DivisionByZeroError} / {@code ArithmeticError} otherwise stay live).
 * The same proofs skip the LLVM zero/overflow guards and after-call
 * throw-pending checks when the result is used ({@see intdivArgsCannotThrow} /
 * {@see intdivCanSkipZeroDivisorGuard}). Compile-time non-negative bit-shift
 * counts skip the negative-count {@code ArithmeticError} blocks
 * ({@see bitShiftCountCanSkipNegativeGuard}). Compile-time shift count
 * {@code 0} is a typed {@code <<}/{@code >>} identity (no {@code shl}/{@code ashr};
 * {@see bitShiftCountIsCompileTimeZero}). Typed {@code |}/{@code ^} with a
 * compile-time {@code 0} operand and {@code &} with {@code -1} are bitwise
 * identities (no {@code and}/{@code or}/{@code xor};
 * {@see bitwiseLogicIsCompileTimeIdentity}). Typed {@code & 0} folds to
 * {@code 0}, {@code | -1} to {@code -1}, and {@code ^ -1} to {@code not}
 * (omit {@code and}/{@code or}/{@code xor}; peer identity folds;
 * {@see bitwiseLogicIsCompileTimeConstantResult}). Same-operand typed
 * {@code $n & $n}/{@code $n | $n} are identity and {@code $n ^ $n} folds to
 * {@code 0} (no {@code and}/{@code or}/{@code xor};
 * {@see bitwiseLogicSameOperandFold}). Same-operand typed {@code $n - $n}
 * folds to {@code 0} (no {@code sub} / overflow intrinsic;
 * {@see nativeLongArithSameOperandFold}). Same-operand typed {@code $n + $n}
 * lowers to {@code shl 1} with {@code ashr} round-trip overflow (peer
 * {@code * 2}; {@see nativeLongArithSameOperandFold}). Same-operand typed
 * {@code $n / $n} folds to {@code 1} and {@code $n % $n} to {@code 0} (omit
 * {@code sdiv}/{@code srem}/exactness; keep {@code DivisionByZeroError} when
 * {@code n == 0}; {@see nativeLongArithSameOperandFold}). Same-operand typed
 * comparisons fold to a constant bool / spaceship 0 (omit {@code icmp} /
 * resource-identity CFG; {@see nativeLongCompareSameOperandFold}). Distinct
 * compile-time long literals ({@code 7 === 7}, {@code 3 <=> 5}) likewise fold
 * (same-operand misses different Value wrappers;
 * {@see nativeLongCompareCompileTimeFold}). Compile-time
 * divisors ≠ {@code -1} skip the typed {@code /} {@code PHP_INT_MIN}/{-1}
 * promote arm ({@see nativeLongDivisorCanSkipNegOneModuloBranch}). Typed
 * {@code / 1} is identity (no {@code sdiv}/{@code srem}/exactness promote;
 * {@see nativeLongDivisorIsCompileTimeOne}). {@code intdiv($n, 1)} is the same
 * identity at the builtin call site; {@code intdiv($n, $n)} folds to {@code 1}
 * (omit {@code sdiv} / {@code INT_MIN}/{-1} {@code ArithmeticError}; keep
 * {@code DivisionByZeroError} when {@code n == 0}; peer typed {@code $n / $n};
 * {@see intdivSameOperandFoldsToOne}). Typed {@code $n ** 0} / {@code pow($n, 0)}
 * folds to {@code 1}, {@code $n ** 1} / {@code pow($n, 1)} is identity, and
 * {@code $n ** 2} / {@code pow($n, 2)} lowers to {@code $n * $n} with smul
 * overflow→float, {@code $n ** 3} / {@code pow($n, 3)} to
 * {@code $n * $n * $n} with chained smul, {@code $n ** 4} /
 * {@code pow($n, 4)} to {@code ($n*$n)*($n*$n)} (square-of-square),
 * {@code $n ** 5} / {@code pow($n, 5)} to {@code ($n*$n*$n)*($n*$n)} with
 * chained smul, {@code $n ** 6} / {@code pow($n, 6)} to
 * {@code ($n*$n*$n)*($n*$n*$n)} (cube-of-cube) with chained smul,
 * {@code $n ** 7} / {@code pow($n, 7)} to
 * {@code (($n*$n*$n)*($n*$n*$n))*$n} with chained smul,
 * {@code $n ** 8} / {@code pow($n, 8)} to
 * {@code (($n*$n)*($n*$n))*(($n*$n)*($n*$n))} with chained smul,
 * {@code $n ** 9} / {@code pow($n, 9)} to
 * {@code (($n*$n*$n)*($n*$n*$n))*($n*$n*$n)} with chained smul,
 * {@code $n ** 10} / {@code pow($n, 10)} to
 * {@code (($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))} with chained smul,
 * {@code $n ** 11} / {@code pow($n, 11)} to
 * {@code ((($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n)))*$n} with chained smul, and
 * {@code $n ** 12} / {@code pow($n, 12)} to
 * {@code (($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n))} with chained smul, and
 * {@code $n ** 13} / {@code pow($n, 13)} to
 * {@code ((($n*$n*$n)*($n*$n*$n))*(($n*$n*$n)*($n*$n*$n)))*$n} with chained smul, and
 * {@code $n ** 14} / {@code pow($n, 14)} to
 * {@code (((($n*$n*$n)*($n*$n*$n))*$n)*((($n*$n*$n)*($n*$n*$n))*$n))} with chained smul, and
 * {@code $n ** 15} / {@code pow($n, 15)} to
 * {@code ((((($n*$n*$n)*($n*$n*$n))*$n)*((($n*$n*$n)*($n*$n*$n))*$n))*$n)} with chained smul, and
 * {@code $n ** 16} / {@code pow($n, 16)} to
 * {@code (((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))} with chained smul, and
 * {@code $n ** 17} / {@code pow($n, 17)} to
 * {@code ((((($n*$n)*($n*$n))*(($n*$n)*($n*$n)))*((($n*$n)*($n*$n))*(($n*$n)*($n*$n))))*$n} with chained smul (omit
 * {@code llvm.pow.f64} / float round-trip;
 * {@see nativeLongPowCompileTimeExponentFold}). {@code intdiv($n, -1)} and typed
 * {@code / -1} lower to {@code negate} with the {@code INT_MIN} guard /
 * float promote only when needed ({@see nativeLongDivisorIsCompileTimeNegOne}).
 * Typed {@code % 1} folds to {@code 0} (peer {@code % -1};
 * {@see nativeLongModuloDivisorFoldsToZero}). Typed {@code + 0}/{@code - 0}/
 * {@code * 1} are arithmetic identities and {@code * 0} folds to {@code 0}
 * (no {@code add}/{@code sub}/{@code mul};
 * {@see nativeLongArithIsCompileTimeIdentityOrZero}). Typed {@code * -1} /
 * {@code -1 *} lowers to {@code negate} with {@code PHP_INT_MIN} → float
 * promote (no {@code llvm.smul.with.overflow};
 * {@see nativeLongMulIsCompileTimeNegOne}). Typed {@code * 2^k} ({@code k} in
 * 1..62) lowers to {@code shl} with {@code ashr} round-trip overflow (no
 * {@code llvm.smul.with.overflow};
 * {@see nativeLongMulCompileTimePowerOfTwoShift}). Remaining compile-time
 * identity/zero operands on typed {@code +}/{@code -}/{@code *} still skip
 * {@code llvm.s{add,sub,mul}.with.overflow}
 * ({@see \PHPCompiler\JIT\JitLongArithOverflow::canSkipOverflowPromote}).
 * Proven-safe {@code str_increment}/
 * {@code str_decrement} literals likewise fold at the call site and skip
 * after-call throw-pending ({@see strIncDecArgsCannotThrow}). {@code hex2bin}/
 * {@code base64_decode}/{@code convert_uudecode} stay live (invalid-input
 * warnings / false returns). Int needles for {@code strpos}/{@code strchr}/…
 * stay live (PHP 8 deprecations). Array {@code str_replace} stays live
 * (element {@code __toString}); discarded {@code implode}/{@code join} elide
 * on typed array pieces + typed string separator (array-first two-arg and
 * soft-null separator stay live); {@code str_replace} {@code &$count}
 * stays live (by-ref write). {@code dirname} with a non-constant {@code $levels}
 * stays live ({@code ValueError} when {@code $levels < 1}). {@code str_getcsv}
 * without an explicit {@code $escape} stays live (PHP 8.4+ omitted-escape DEP).
 * {@code similar_text} with {@code &$percent} stays live (by-ref write).
 * {@code strval} on arrays/objects stays live (array-to-string warning /
 * {@code __toString}). {@code base_convert} with non-constant bases stays
 * live ({@code ValueError} outside [2,36]). {@code version_compare} with an
 * unknown typed operator stays live ({@code ValueError} on invalid ops).
 * Single-array {@code min}/{@code max} stays live (element compare / object
 * handlers). {@code clamp} elides only when min/max are compile-time numerics
 * with {@code min <= max} and neither is NAN (runtime / inverted / NAN bounds
 * stay live — {@code ValueError}). Soft-null {@code checkdate} stays live (deprecate). Non-string
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
    use DiscardedPureCallElisionStringOps;
    use DiscardedPureCallElisionArrayOps;
    use DiscardedPureCallElisionDateCalOps;
    use DiscardedPureCallElisionFormatAnalyzeOps;
    use DiscardedPureCallElisionMathAndHashOps;
    use DiscardedPureCallElisionRuntimeInfoOps;
    use DiscardedPureCallElisionNativeLongFolds;
    use DiscardedPureCallElisionIntrospectOps;

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
        if (self::tryElidePureStrIncDecNoSideEffect($toCall, $callArgs)) {
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
        if (self::tryElidePureImplodeJoinNoSideEffect($toCall, $callArgs)) {
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
        if (self::tryElidePureClampNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureIntdivNoSideEffect($toCall, $callArgs)) {
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
        if (self::tryElidePureArrayCombineNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureRangeNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureCountCharsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureStrWordCountNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureStripTagsNoSideEffect($toCall, $callArgs)) {
            return true;
        }
        if (self::tryElidePureGetHtmlTranslationTableNoSideEffect($toCall, $callArgs)) {
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
    private static function clampArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1], $callArgs[2])
            || isset($callArgs[3])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !$callArgs[2] instanceof Variable
        ) {
            return false;
        }
        // Value may be runtime typed numeric; bounds must be proven at compile time.
        if (!self::mathArgAllowsDiscardedElision($callArgs[0])) {
            return false;
        }
        $min = self::compileTimeNumericScalar($callArgs[1]);
        $max = self::compileTimeNumericScalar($callArgs[2]);
        if (null === $min || null === $max) {
            return false;
        }
        // php-src: NAN min/max → ValueError; min > max → ValueError.
        if ($min !== $min || $max !== $max) {
            return false;
        }

        return $min <= $max;
    }


    /**
     * @param array<int, Variable> $callArgs
     */
    private static function intdivArgsAllowDiscardedElision(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }
        if (
            !self::mathArgAllowsDiscardedElision($callArgs[0])
            || !self::mathArgAllowsDiscardedElision($callArgs[1])
        ) {
            return false;
        }
        // Z_PARAM_LONG truncation — only proven compile-time longs are safe for
        // DivisionByZeroError / ArithmeticError proofs (float 0.5 → 0).
        $divisor = self::compileTimeLongScalar($callArgs[1]);
        if (null === $divisor || 0 === $divisor) {
            return false;
        }
        // php-src: PHP_INT_MIN / -1 → ArithmeticError; runtime dividend with
        // divisor -1 cannot prove ≠ INT_MIN.
        if (-1 === $divisor) {
            $dividend = self::compileTimeLongScalar($callArgs[0]);
            if (null === $dividend || \PHP_INT_MIN === $dividend) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compile-time int / finite-in-range float / numeric-string → zend_long
     * truncation for intdiv proofs (php-src {@code Z_PARAM_LONG}).
     *
     * KIND_VARIABLE (alloca) and boxed {@code __value__} slots are mutable at
     * runtime — {@see Variable::$compileTimeLong} is set on the first assign and
     * goes stale in loops. Folding {@code $s += $i} as {@code 0 + $i} made AOT
     * print the last {@code $i} (call-heavy / #36385 bench-gate; peer #32605).
     */
    private static function compileTimeLongScalar(Variable $arg): ?int
    {
        // Mutable storage: never treat as a foldable compile-time long.
        if (Variable::KIND_VARIABLE === $arg->kind) {
            return null;
        }
        if (JitValueBox::isValueOperand($arg)) {
            return null;
        }
        if (null !== $arg->compileTimeLong) {
            return $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            $f = $arg->compileTimeFloat;
            if ($f !== $f || \is_infinite($f)) {
                return null;
            }
            if ($f > (float) \PHP_INT_MAX || $f < (float) \PHP_INT_MIN) {
                return null;
            }

            return (int) $f;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit || !is_numeric($lit)) {
            return null;
        }
        // Reject non-integer numeric strings that truncate to 0 unexpectedly
        // only via float path; (int)"1.5" === 1 matches Z_PARAM_LONG.
        $asFloat = (float) $lit;
        if ($asFloat > (float) \PHP_INT_MAX || $asFloat < (float) \PHP_INT_MIN) {
            return null;
        }

        return (int) $lit;
    }

    /**
     * Compile-time int/float/numeric-string scalar for clamp bound proofs.
     *
     * Same KIND_VARIABLE / boxed-value guard as {@see compileTimeLongScalar}
     * (#36385 / peer #32605).
     */
    private static function compileTimeNumericScalar(Variable $arg): ?float
    {
        if (Variable::KIND_VARIABLE === $arg->kind) {
            return null;
        }
        if (JitValueBox::isValueOperand($arg)) {
            return null;
        }
        if (null !== $arg->compileTimeLong) {
            return (float) $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            return $arg->compileTimeFloat;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);

        return null !== $lit && is_numeric($lit) ? (float) $lit : null;
    }


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
     * Multi-arg builtins require exact arity ({@code ArgumentCountError} otherwise).
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
        $argc = \count($callArgs);
        if ('log' === $name) {
            // log(num) or log(num, base) — both legal; other arities ArgumentCountError.
            if ($argc < 1 || $argc > 2) {
                return false;
            }
        } elseif ('round' === $name) {
            // round(num [, precision [, mode]]) — php-src math.c PHP_FUNCTION(round).
            // Excess argc → ArgumentCountError; mode ValueError gated below.
            if ($argc < 1 || $argc > 3) {
                return false;
            }
        } else {
            $required = self::pureMathBuiltinRequiredArgc($name);
            if (null !== $required && $argc !== $required) {
                return false;
            }
        }
        if ('round' === $name) {
            return self::roundArgsAllowDiscardedElision($callArgs);
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::mathArgAllowsDiscardedElision($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exact argc for math.c builtins that reject wrong arity with
     * {@code ArgumentCountError}. Null only for {@code pi} (handled above);
     * {@code log} / {@code round} are gated separately (1..2 / 1..3).
     */
    private static function pureMathBuiltinRequiredArgc(string $nameLc): ?int
    {
        switch ($nameLc) {
            case 'hypot':
            case 'fmod':
            case 'atan2':
            case 'pow':
            case 'fpow':
            case 'fdiv':
            case 'nextafter':
                return 2;
            default:
                // Unary math.c entries (abs/sqrt/sin/…); excess argc stays live.
                return 1;
        }
    }

    /**
     * Discarded {@code round} — num + optional precision are Z_PARAM_DOUBLE /
     * LONG family (soft-null deprecates). Optional mode must not
     * {@code ValueError} under {@see CompilerVersion::supportsRoundingModeEnum}
     * (compile-time {@code PHP_ROUND_*} only); without the enum gate, typed
     * numeric mode is side-effect-free (php-src treats unknown ints as half-up).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function roundArgsAllowDiscardedElision(array $callArgs): bool
    {
        $argc = \count($callArgs);
        for ($i = 0; $i < $argc && $i < 2; ++$i) {
            if (!$callArgs[$i] instanceof Variable || !self::mathArgAllowsDiscardedElision($callArgs[$i])) {
                return false;
            }
        }
        if ($argc < 3) {
            return true;
        }
        if (!$callArgs[2] instanceof Variable) {
            return false;
        }

        return self::roundModeArgAllowsDiscardedElision($callArgs[2]);
    }

    /**
     * Mode arg for discarded {@code round} — soft-null / object / value-box stay
     * live; compile-time int/float must be a valid {@code PHP_ROUND_*} when the
     * RoundingMode enum profile is on; otherwise typed numeric scalars are OK.
     */
    private static function roundModeArgAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        $compileTime = null;
        if (null !== $arg->compileTimeLong) {
            $compileTime = (int) $arg->compileTimeLong;
        } elseif (null !== $arg->compileTimeFloat) {
            // Z_PARAM_LONG truncates toward zero (peer intdiv float divisor).
            $compileTime = (int) $arg->compileTimeFloat;
        }
        if (null !== $compileTime) {
            if (!CompilerVersion::supportsRoundingModeEnum()) {
                return true;
            }

            return VmRoundMode::isValidLegacyIntMode($compileTime);
        }
        if (
            Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type
        ) {
            // Runtime mode can still ValueError when RoundingMode is enforced.
            return !CompilerVersion::supportsRoundingModeEnum();
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit && is_numeric($lit)) {
            $asLong = (int) $lit;
            if (!CompilerVersion::supportsRoundingModeEnum()) {
                return true;
            }

            return VmRoundMode::isValidLegacyIntMode($asLong);
        }

        return false;
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
