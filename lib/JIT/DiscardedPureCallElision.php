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

/**
 * Elide discarded calls to compile-time-pure builtins (#23483 / #36386 call-overhead).
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
 * {@code (($n*$n*$n)*($n*$n*$n))*($n*$n*$n)} with chained smul, and
 * {@code $n ** 10} / {@code pow($n, 10)} to
 * {@code (($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))} with chained smul (omit
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
     * Discarded {@code array_combine} — php-src {@code ext/standard/array.c}
     * {@code PHP_FUNCTION(array_combine)}. Length mismatch is a {@code ValueError}.
     * Only equal-length non-empty compile-time packs are elided.
     * {@code compileTimeEmptyArrayLiteral} is also set on {@code array} RECV
     * slots, so empty-literal alone is not a size proof.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayCombineNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('array_combine' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
            return false;
        }
        $keys = $callArgs[0];
        $values = $callArgs[1];
        if (!self::isTypedArrayArg($keys) || !self::isTypedArrayArg($values)) {
            return false;
        }
        // Empty compileTimeArray/Assoc ([]) is an in-progress marker; RECV
        // slots may also carry compileTimeEmptyArrayLiteral — neither proves
        // equal runtime lengths (ValueError).
        if (
            null !== $keys->compileTimeArray
            && null !== $values->compileTimeArray
            && \count($keys->compileTimeArray) > 0
            && \count($keys->compileTimeArray) === \count($values->compileTimeArray)
        ) {
            return true;
        }
        if (
            null !== $keys->compileTimeAssoc
            && null !== $values->compileTimeAssoc
            && \count($keys->compileTimeAssoc) > 0
            && \count($keys->compileTimeAssoc) === \count($values->compileTimeAssoc)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Discarded {@code range} — php-src {@code ext/standard/array.c}
     * {@code PHP_FUNCTION(range)}. Two typed numeric endpoints use default
     * step ±1 (no ValueError). Three-arg form requires compile-time longs that
     * pass the zero / increasing-negative / oversized step checks (peer
     * {@see \PHPCompiler\ext\standard\RangeIntJitHelper::intRangeCopy}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureRangeNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('range' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[3])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
            return false;
        }
        if (
            !self::mathArgAllowsDiscardedElision($callArgs[0])
            || !self::mathArgAllowsDiscardedElision($callArgs[1])
        ) {
            return false;
        }
        if (!isset($callArgs[2])) {
            // Default step is select(±1) — never 0 / never oversized vs span.
            return true;
        }
        if (!$callArgs[2] instanceof Variable) {
            return false;
        }
        $start = $callArgs[0]->compileTimeLong;
        $end = $callArgs[1]->compileTimeLong;
        $step = $callArgs[2]->compileTimeLong;
        if (null === $start || null === $end || null === $step) {
            // Runtime step / endpoints can still ValueError.
            return false;
        }

        return self::compileTimeRangeStepAllowsDiscardedElision($start, $end, $step);
    }

    /**
     * Mirror {@see \PHPCompiler\ext\standard\RangeIntJitHelper::intRangeCopy}
     * ValueError guards for discarded elision (PROFILE-agnostic: reject every
     * shape that can throw on any supported profile).
     */
    private static function compileTimeRangeStepAllowsDiscardedElision(
        int $start,
        int $end,
        int $step
    ): bool {
        if (0 === $step) {
            return false;
        }
        // php-src: only end > start rejects a negative step; equal endpoints stay a singleton.
        if ($start < $end && $step < 0) {
            return false;
        }
        if ($start !== $end) {
            $span = $start > $end ? ($start - $end) : ($end - $start);
            $stepAbs = $step < 0 ? -$step : $step;
            if ($span < $stepAbs) {
                return false;
            }
        }

        return true;
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
     * Public for {@see NoThrowCallElision} — when true, discarded or used
     * {@code intdiv} cannot {@code DivisionByZeroError} / {@code ArithmeticError}
     * / {@code TypeError} / soft-null deprecate (#36386 / peer #37153).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function intdivArgsCannotThrow(array $callArgs): bool
    {
        return self::intdivArgsAllowDiscardedElision($callArgs);
    }

    /**
     * Skip the LLVM zero-divisor branch when the divisor truncates to a
     * compile-time long ≠ 0 (php-src {@code Z_PARAM_LONG}).
     *
     * Also used for typed native-long {@code /} and {@code %} (zend_operators.c
     * {@code div_function} / {@code mod_function}) — same proof (#36386).
     */
    public static function intdivCanSkipZeroDivisorGuard(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && 0 !== $d;
    }

    /**
     * Skip the {@code n % -1 → 0} PHI when the divisor is a compile-time long
     * proven ≠ {@code -1} (php-src {@code mod_function}; LLVM {@code srem}
     * of {@code INT_MIN}/{-1} is poison only for {@code -1}).
     *
     * Also skips the typed native-long {@code /} {@code PHP_INT_MIN}/{-1}
     * promote arm ({@see \PHPCompiler\JIT\JitLongDiv::binaryNativeLong}).
     */
    public static function nativeLongDivisorCanSkipNegOneModuloBranch(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && -1 !== $d;
    }

    /**
     * Typed native-long {@code /} with compile-time divisor {@code 1} is identity —
     * emit the dividend (no {@code sdiv}/{@code srem}, no zero-guard, no
     * exactness/promote CFG). {@code 1 / $n} is not identity.
     *
     * php-src: Zend/zend_operators.c div_function after convert_to_long.
     * Peer {@code | 0} / {@code << 0} / {@code + 0} (#37212 / #37208 / #37200).
     */
    public static function nativeLongDivisorIsCompileTimeOne(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && 1 === $d;
    }

    /**
     * Compile-time divisor {@code -1} for {@code intdiv($n, -1)} and typed
     * {@code / -1} → {@code -n} (php-src {@code PHP_FUNCTION(intdiv)} /
     * {@code div_function}; peer typed {@code * -1} / {@code % -1} → {@code 0}).
     * Callers still emit the {@code PHP_INT_MIN} {@code ArithmeticError} (intdiv)
     * or float promote ({@code /}) unless {@see intdivCanSkipIntMinNegOneGuard}
     * proves safe.
     */
    public static function nativeLongDivisorIsCompileTimeNegOne(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && -1 === $d;
    }

    /**
     * Typed native-long {@code %} with compile-time divisor {@code ±1} is always
     * {@code 0} (php-src {@code mod_function}; {@code n % -1} and {@code n % 1}).
     * Emit constant {@code 0} without {@code srem} / zero-guard / neg-one PHI.
     */
    public static function nativeLongModuloDivisorFoldsToZero(Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);

        return null !== $d && (1 === $d || -1 === $d);
    }

    /**
     * Skip the LLVM negative bit-shift {@code ArithmeticError} when the count
     * truncates to a compile-time long {@code ≥ 0} (php-src
     * {@code shift_left_function} / {@code shift_right_function}; peer typed
     * {@code /}/{@code %} proven-divisor, #36386).
     */
    public static function bitShiftCountCanSkipNegativeGuard(Variable $count): bool
    {
        $c = self::compileTimeLongScalar($count);

        return null !== $c && $c >= 0;
    }

    /**
     * Typed {@code <<}/{@code >>} with compile-time count {@code 0} is identity —
     * emit the left operand (no {@code shl}/{@code ashr}, no negative-count
     * guard). Peer {@code + 0}/{@code - 0} overflow skip (#37200 / #36386).
     *
     * @see php-src Zend/zend_operators.c shift_left_function / shift_right_function
     */
    public static function bitShiftCountIsCompileTimeZero(Variable $count): bool
    {
        $c = self::compileTimeLongScalar($count);

        return null !== $c && 0 === $c;
    }

    /**
     * Typed native-long {@code &}|{@code ^} identity when one operand is a
     * compile-time long that does not change the other:
     * {@code | 0}, {@code ^ 0}, {@code & -1} (and the mirrored forms).
     * Emit the non-identity operand (no {@code and}/{@code or}/{@code xor}).
     *
     * php-src: Zend/zend_operators.c bitwise_and/or/xor_function after
     * {@code convert_to_long}. Peer shift-count {@code 0} (#37208 / #36386).
     *
     * @return 'left'|'right'|null which operand to keep, or null when not identity
     */
    public static function bitwiseLogicIsCompileTimeIdentity(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (\PHPCompiler\OpCode::TYPE_BITWISE_OR === $opType
            || \PHPCompiler\OpCode::TYPE_BITWISE_XOR === $opType
        ) {
            if (0 === $a) {
                return 'right';
            }
            if (0 === $b) {
                return 'left';
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_AND === $opType) {
            // All-bits-set mask is identity for signed zend_long (two's complement).
            if (-1 === $a) {
                return 'right';
            }
            if (-1 === $b) {
                return 'left';
            }

            return null;
        }

        return null;
    }

    /**
     * Typed native-long bitwise constant results when one operand is a
     * compile-time long that forces the outcome:
     * {@code & 0} → {@code 0}, {@code | -1} → {@code -1}, {@code ^ -1} →
     * {@code ~} of the other operand (and the mirrored forms).
     *
     * php-src: Zend/zend_operators.c bitwise_and/or/xor_function after
     * {@code convert_to_long}. Peer identity folds ({@see bitwiseLogicIsCompileTimeIdentity}).
     *
     * @return array{kind: 'zero'|'all_ones'|'not', keep: 'left'|'right'}|null
     *   {@code keep} is the surviving operand for {@code not}; unused for
     *   {@code zero}/{@code all_ones} (still names which side was non-const).
     */
    public static function bitwiseLogicIsCompileTimeConstantResult(
        int $opType,
        Variable $left,
        Variable $right
    ): ?array {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (\PHPCompiler\OpCode::TYPE_BITWISE_AND === $opType) {
            if (0 === $a) {
                return ['kind' => 'zero', 'keep' => 'right'];
            }
            if (0 === $b) {
                return ['kind' => 'zero', 'keep' => 'left'];
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_OR === $opType) {
            if (-1 === $a) {
                return ['kind' => 'all_ones', 'keep' => 'right'];
            }
            if (-1 === $b) {
                return ['kind' => 'all_ones', 'keep' => 'left'];
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_XOR === $opType) {
            // {@code n ^ -1} ≡ {@code ~n} (two's complement zend_long).
            if (-1 === $a) {
                return ['kind' => 'not', 'keep' => 'right'];
            }
            if (-1 === $b) {
                return ['kind' => 'not', 'keep' => 'left'];
            }

            return null;
        }

        return null;
    }

    /**
     * Typed native-long {@code &}|{@code ^} when both operands are the same
     * storage / SSA payload: {@code $n & $n} / {@code $n | $n} → {@code $n},
     * {@code $n ^ $n} → {@code 0} (omit {@code and}/{@code or}/{@code xor}).
     *
     * Algebra holds for any zend_long; peer compile-time {@code |0}/{@code ^0}/
     * {@code &-1} identity (#37212 / #36386) and constant folds
     * ({@see bitwiseLogicIsCompileTimeConstantResult}).
     *
     * php-src: Zend/zend_operators.c bitwise_and/or/xor_function.
     *
     * @return 'left'|'zero'|null keep left, fold to 0, or null when not same-operand
     */
    public static function bitwiseLogicSameOperandFold(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        if (!self::nativeLongOperandsAreSame($left, $right)) {
            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_AND === $opType
            || \PHPCompiler\OpCode::TYPE_BITWISE_OR === $opType
        ) {
            return 'left';
        }
        if (\PHPCompiler\OpCode::TYPE_BITWISE_XOR === $opType) {
            return 'zero';
        }

        return null;
    }

    /**
     * Typed native-long arithmetic when both operands are the same storage /
     * SSA payload:
     * - {@code $n - $n} → {@code 0} (omit {@code sub} /
     *   {@code llvm.ssub.with.overflow}). Algebra holds for every zend_long
     *   including {@code PHP_INT_MIN} ({@code ZEND_SIGNED_SUB_OVERFLOW} is a
     *   no-op for equal operands).
     * - {@code $n + $n} → {@code shl 1} with {@code ashr} overflow (omit
     *   {@code llvm.sadd.with.overflow}; same shape as compile-time {@code * 2}).
     * - {@code $n / $n} → {@code 1} (omit {@code sdiv}/{@code srem}/exactness;
     *   callers keep {@code DivisionByZeroError} when {@code n == 0}). Equal
     *   nonzero longs always divide exactly ({@code INT_MIN}/{@code INT_MIN}
     *   is 1, not the INT_MIN/−1 promote case).
     * - {@code $n % $n} → {@code 0} (omit {@code srem} / neg-one PHI; callers
     *   keep the zero-divisor guard).
     *
     * Peer same-operand bitwise ({@see bitwiseLogicSameOperandFold}),
     * compile-time {@code - 0}/{@code + 0} identity
     * ({@see nativeLongArithIsCompileTimeIdentityOrZero}),
     * {@see nativeLongMulCompileTimePowerOfTwoShift}, and compile-time
     * {@code % ±1} / {@code / 1} ({@see nativeLongModuloDivisorFoldsToZero} /
     * {@see nativeLongDivisorIsCompileTimeOne}).
     *
     * php-src: Zend/zend_operators.c sub_function / add_function /
     * div_function / mod_function / ZEND_SIGNED_{SUB,ADD}_OVERFLOW.
     *
     * @return 'zero'|'shl1'|'one'|null fold to 0, shl×2, const 1, or null when N/A
     */
    public static function nativeLongArithSameOperandFold(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        if (!self::nativeLongOperandsAreSame($left, $right)) {
            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_MINUS === $opType
            || \PHPCompiler\OpCode::TYPE_MODULO === $opType
        ) {
            return 'zero';
        }
        if (\PHPCompiler\OpCode::TYPE_PLUS === $opType) {
            return 'shl1';
        }
        if (\PHPCompiler\OpCode::TYPE_DIV === $opType) {
            return 'one';
        }

        return null;
    }

    /**
     * {@code intdiv($n, $n)} → {@code 1} when both args are the same typed
     * native-long storage / SSA payload (peer typed {@code $n / $n}).
     *
     * Omits {@code sdiv} and the {@code PHP_INT_MIN}/{-1} {@code ArithmeticError}
     * arm (equal nonzero longs always divide exactly; {@code INT_MIN}/{@code INT_MIN}
     * is 1, not the INT_MIN/−1 case). Callers keep {@code DivisionByZeroError}
     * when {@code n == 0}.
     *
     * php-src: ext/standard/math.c {@code PHP_FUNCTION(intdiv)}.
     */
    public static function intdivSameOperandFoldsToOne(
        Variable $left,
        Variable $right
    ): bool {
        return self::nativeLongOperandsAreSame($left, $right);
    }

    /**
     * Typed integer {@code **} / {@code pow()} when the exponent is a
     * compile-time long:
     * - {@code $n ** 0} / {@code pow($n, 0)} → {@code 1} (incl. {@code 0 ** 0})
     * - {@code $n ** 1} / {@code pow($n, 1)} → {@code $n} (identity)
     * - {@code $n ** 2} / {@code pow($n, 2)} → {@code $n * $n} with
     *   {@code llvm.smul.with.overflow} → float promote on overflow (same
     *   shape as typed {@code *} / {@code mul_function})
     * - {@code $n ** 3} / {@code pow($n, 3)} → {@code $n * $n * $n} with
     *   chained smul overflow→float (first {@code n*n}, then {@code ×n})
     * - {@code $n ** 4} / {@code pow($n, 4)} → {@code ($n*$n)*($n*$n)} with
     *   chained smul overflow→float (square, then square-of-square)
     * - {@code $n ** 5} / {@code pow($n, 5)} → {@code ($n*$n*$n)*($n*$n)} with
     *   chained smul overflow→float (cube × square)
     * - {@code $n ** 6} / {@code pow($n, 6)} → {@code ($n*$n*$n)*($n*$n*$n)} with
     *   chained smul overflow→float (cube × cube)
     * - {@code $n ** 7} / {@code pow($n, 7)} → {@code (($n*$n*$n)*($n*$n*$n))*$n}
     *   with chained smul overflow→float (cube × cube × n)
     * - {@code $n ** 8} / {@code pow($n, 8)} → {@code (($n*$n)*($n*$n))*(($n*$n)*($n*$n))}
     *   with chained smul overflow→float (fourth × fourth)
     * - {@code $n ** 9} / {@code pow($n, 9)} → {@code (($n*$n*$n)*($n*$n*$n))*($n*$n*$n)}
     *   with chained smul overflow→float (sixth × cube)
     * - {@code $n ** 10} / {@code pow($n, 10)} → {@code (($n*$n*$n)*($n*$n))*(($n*$n*$n)*($n*$n))}
     *   with chained smul overflow→float (fifth × fifth)
     *
     * Omits {@code llvm.pow.f64} and the siToFp/fpToSi round-trip on the
     * integer fast path ({@see \PHPCompiler\ext\standard\JitPow}). Peer
     * compile-time {@code * 1} identity ({@see nativeLongArithIsCompileTimeIdentityOrZero})
     * and {@code * 2^k} shl ({@see nativeLongMulCompileTimePowerOfTwoShift}).
     *
     * Float exponents ({@code 0.0}/{@code 1.0}/{@code 2.0}/{@code 3.0}/{@code 4.0}/{@code 5.0}/{@code 6.0}/{@code 7.0}/{@code 8.0}/{@code 9.0}/{@code 10.0}) stay
     * on the float path — Zend returns {@code float} for those shapes.
     *
     * php-src: Zend/zend_operators.c {@code pow_function} /
     * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
     * {@code PHP_FUNCTION(pow)}.
     *
     * @return 'one'|'identity'|'square'|'cube'|'fourth'|'fifth'|'sixth'|'seventh'|'eighth'|'ninth'|'tenth'|null fold to 1, keep base, mul square/cube/fourth/fifth/sixth/seventh/eighth/ninth/tenth, or null
     */
    public static function nativeLongPowCompileTimeExponentFold(
        Variable $exponent
    ): ?string {
        $e = self::compileTimeLongScalar($exponent);
        if (null === $e) {
            return null;
        }
        if (0 === $e) {
            return 'one';
        }
        if (1 === $e) {
            return 'identity';
        }
        if (2 === $e) {
            return 'square';
        }
        if (3 === $e) {
            return 'cube';
        }
        if (4 === $e) {
            return 'fourth';
        }
        if (5 === $e) {
            return 'fifth';
        }
        if (6 === $e) {
            return 'sixth';
        }
        if (7 === $e) {
            return 'seventh';
        }
        if (8 === $e) {
            return 'eighth';
        }
        if (9 === $e) {
            return 'ninth';
        }
        if (10 === $e) {
            return 'tenth';
        }

        return null;
    }

    /**
     * Typed native-long relational / equality / spaceship when both operands
     * are the same storage / SSA payload:
     * - {@code $n === $n} / {@code $n == $n} → {@code true}
     * - {@code $n !== $n} / {@code $n != $n} → {@code false}
     * - {@code $n < $n} / {@code $n > $n} → {@code false}
     * - {@code $n <= $n} / {@code $n >= $n} → {@code true}
     * - {@code $n <=> $n} → {@code 0}
     *
     * Omits {@code icmp} and the resource-identity equal CFG
     * ({@see \PHPCompiler\JIT\JitValueCompare::nativeLongEqualWithResourceIdentity}).
     * Same-handle resource {@code ===} is still true (left ≡ right).
     *
     * Peer same-operand arith/bitwise ({@see nativeLongArithSameOperandFold} /
     * {@see bitwiseLogicSameOperandFold}).
     *
     * php-src: Zend/zend_operators.c compare_function /
     * is_identical_function / is_equal_function / zend_compare_longs.
     *
     * @return 'true'|'false'|'zero'|null const bool, spaceship 0, or null when N/A
     */
    public static function nativeLongCompareSameOperandFold(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        if (!self::nativeLongOperandsAreSame($left, $right)) {
            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_EQUAL === $opType
            || \PHPCompiler\OpCode::TYPE_SMALLER_OR_EQUAL === $opType
            || \PHPCompiler\OpCode::TYPE_GREATER_OR_EQUAL === $opType
        ) {
            return 'true';
        }
        if (\PHPCompiler\OpCode::TYPE_NOT_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_NOT_EQUAL === $opType
            || \PHPCompiler\OpCode::TYPE_SMALLER === $opType
            || \PHPCompiler\OpCode::TYPE_GREATER === $opType
        ) {
            return 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_SPACESHIP === $opType) {
            return 'zero';
        }

        return null;
    }

    /**
     * Typed native-long relational / equality / spaceship when both operands
     * are compile-time longs (distinct SSA temps / literals that
     * {@see nativeLongCompareSameOperandFold} misses because Value wrappers
     * differ):
     * - {@code 7 === 7} / {@code 7 == 7} / {@code 3 <= 7} / {@code 7 >= 3} → true
     * - {@code 7 === 3} / {@code 7 != 3} / {@code 7 < 3} / {@code 3 > 7} → false
     * - {@code 7 <=> 3} → {@code 1}, {@code 3 <=> 7} → {@code -1},
     *   {@code 7 <=> 7} → {@code 0}
     *
     * Omits {@code icmp} and the resource-identity equal CFG
     * ({@see \PHPCompiler\JIT\JitValueCompare::nativeLongEqualWithResourceIdentity}).
     *
     * Peer same-operand compare ({@see nativeLongCompareSameOperandFold}) /
     * compile-time arith ({@see \PHPCompiler\JIT\JitLongArithOverflow::tryFoldBinary}).
     *
     * php-src: Zend/zend_operators.c compare_function /
     * is_identical_function / is_equal_function / zend_compare_longs.
     *
     * @return 'true'|'false'|int|null  int is spaceship −1|0|1
     */
    public static function nativeLongCompareCompileTimeFold(
        int $opType,
        Variable $left,
        Variable $right
    ): string|int|null {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (null === $a || null === $b) {
            return null;
        }
        $cmp = $a <=> $b;
        if (\PHPCompiler\OpCode::TYPE_SPACESHIP === $opType) {
            return $cmp;
        }
        if (\PHPCompiler\OpCode::TYPE_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_EQUAL === $opType
        ) {
            return 0 === $cmp ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_NOT_IDENTICAL === $opType
            || \PHPCompiler\OpCode::TYPE_NOT_EQUAL === $opType
        ) {
            return 0 !== $cmp ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_SMALLER === $opType) {
            return $cmp < 0 ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_GREATER === $opType) {
            return $cmp > 0 ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_SMALLER_OR_EQUAL === $opType) {
            return $cmp <= 0 ? 'true' : 'false';
        }
        if (\PHPCompiler\OpCode::TYPE_GREATER_OR_EQUAL === $opType) {
            return $cmp >= 0 ? 'true' : 'false';
        }

        return null;
    }

    /**
     * Same-operand or both-compile-time typed native-long compare fold.
     *
     * @return 'true'|'false'|int|null  int is spaceship −1|0|1
     */
    public static function nativeLongCompareFold(
        int $opType,
        Variable $left,
        Variable $right
    ): string|int|null {
        $same = self::nativeLongCompareSameOperandFold($opType, $left, $right);
        if (null !== $same) {
            return 'zero' === $same ? 0 : $same;
        }

        return self::nativeLongCompareCompileTimeFold($opType, $left, $right);
    }

    /**
     * True when both operands are typed native-long and name the same alloca or
     * SSA value (two Operand wrappers → one payload).
     */
    private static function nativeLongOperandsAreSame(Variable $left, Variable $right): bool
    {
        if (Variable::TYPE_NATIVE_LONG !== $left->type || $left->type !== $right->type) {
            return false;
        }
        if ($left === $right) {
            return true;
        }

        return $left->value === $right->value;
    }

    /**
     * Typed native-long {@code *} with a compile-time {@code -1} operand is
     * {@code zendi_negate_function} — emit {@code negate} of the other operand
     * (no {@code llvm.smul.with.overflow}). Callers still promote
     * {@code PHP_INT_MIN} → float unless
     * {@see \PHPCompiler\JIT\JitLongArithOverflow::canSkipOverflowPromote}
     * proves safe (peer {@code intdiv($n, -1)} / unary −).
     *
     * php-src: Zend/zend_operators.c mul_function / zendi_negate_function.
     *
     * @return 'left'|'right'|null which operand to negate, or null when not {@code * -1}
     */
    public static function nativeLongMulIsCompileTimeNegOne(
        Variable $left,
        Variable $right
    ): ?string {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (-1 === $a) {
            return 'right';
        }
        if (-1 === $b) {
            return 'left';
        }

        return null;
    }

    /**
     * Typed native-long {@code *} with a compile-time positive power-of-two
     * factor {@code 2^k} ({@code k} in 1..62) may lower to {@code shl} of the
     * other operand (overflow via {@code ashr} round-trip ≠ src → float
     * promote; peer {@code * -1} / {@code * 1}).
     *
     * php-src: Zend/zend_operators.c mul_function /
     * {@code ZEND_LONG_MUL_OVERFLOW}. {@code * 1} stays
     * {@see nativeLongArithIsCompileTimeIdentityOrZero}; {@code * -1} stays
     * {@see nativeLongMulIsCompileTimeNegOne}. Negative factors and {@code 2^63}
     * (stored as {@code PHP_INT_MIN}) are not powers of two here.
     *
     * @return array{side: 'left'|'right', shift: int}|null which operand to
     *         shift and the shift count, or null when not {@code * 2^k}
     */
    public static function nativeLongMulCompileTimePowerOfTwoShift(
        Variable $left,
        Variable $right
    ): ?array {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (null !== $a) {
            $shift = self::positivePowerOfTwoShift($a);
            if (null !== $shift) {
                return ['side' => 'right', 'shift' => $shift];
            }
        }
        if (null !== $b) {
            $shift = self::positivePowerOfTwoShift($b);
            if (null !== $shift) {
                return ['side' => 'left', 'shift' => $shift];
            }
        }

        return null;
    }

    /**
     * Shift count for a positive power-of-two factor {@code 2^k} ({@code k} in
     * 1..62), or null when not applicable ({@code * 1} / negatives / non-pow2).
     */
    private static function positivePowerOfTwoShift(int $factor): ?int
    {
        if ($factor < 2) {
            return null;
        }
        if (0 !== ($factor & ($factor - 1))) {
            return null;
        }
        $shift = 0;
        $v = $factor;
        while (0 === ($v & 1)) {
            ++$shift;
            $v >>= 1;
        }

        return $shift >= 1 && $shift <= 62 ? $shift : null;
    }

    /**
     * Typed native-long {@code +}/{@code -}/{@code *} identity / zero when one
     * operand is a compile-time long that does not change the other (or forces
     * zero):
     * {@code + 0}, {@code - 0}, {@code * 1} (and mirrored {@code 0 +}/{@code 1 *}),
     * and {@code * 0} → constant {@code 0}.
     * Emit the kept operand / {@code 0} (no {@code add}/{@code sub}/{@code mul},
     * no overflow intrinsic). {@code 0 - $n} is not identity; {@code * -1} uses
     * {@see nativeLongMulIsCompileTimeNegOne} → negate.
     *
     * php-src: Zend/zend_operators.c add/sub/mul_function after convert_to_long;
     * {@code ZEND_SIGNED_*_OVERFLOW} is a no-op for these shapes.
     * Peer {@code / 1} / {@code | 0} / {@code << 0} (#37214 / #37212 / #37208).
     *
     * @return 'left'|'right'|'zero'|null which result to emit, or null when not folded
     */
    public static function nativeLongArithIsCompileTimeIdentityOrZero(
        int $opType,
        Variable $left,
        Variable $right
    ): ?string {
        $a = self::compileTimeLongScalar($left);
        $b = self::compileTimeLongScalar($right);
        if (\PHPCompiler\OpCode::TYPE_PLUS === $opType) {
            if (0 === $a) {
                return 'right';
            }
            if (0 === $b) {
                return 'left';
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_MINUS === $opType) {
            // Only right-hand 0 is identity; {@code 0 - PHP_INT_MIN} overflows.
            if (0 === $b) {
                return 'left';
            }

            return null;
        }
        if (\PHPCompiler\OpCode::TYPE_MUL === $opType) {
            if (0 === $a || 0 === $b) {
                return 'zero';
            }
            if (1 === $a) {
                return 'right';
            }
            if (1 === $b) {
                return 'left';
            }

            return null;
        }

        return null;
    }

    /**
     * Skip the LLVM double zero-divisor branch when the divisor is a
     * compile-time numeric proven ≠ {@code 0.0} (NAN is not equal to 0 under
     * ordered compare — php-src leaves {@code / NAN} as NAN).
     */
    public static function doubleDivisorCanSkipZeroGuard(Variable $divisor): bool
    {
        $d = self::compileTimeNumericScalar($divisor);
        if (null === $d) {
            return false;
        }
        // +0.0 / -0.0 must keep DivisionByZeroError; NAN ≠ 0 → safe to skip.
        return 0.0 !== $d;
    }

    /**
     * Compile-time {@code log()} base as a float (php-src {@code Z_PARAM_DOUBLE}).
     * Null when the base is not a compile-time numeric scalar.
     */
    public static function compileTimeLogBase(Variable $base): ?float
    {
        return self::compileTimeNumericScalar($base);
    }

    /**
     * Skip {@code log()} base≤0 {@code ValueError} when the base is a
     * compile-time float {@code > 0} or {@code NAN} (php-src math.c: NAN is
     * not ≤ 0). Soft-null / runtime typed / ≤0 stay live (#36386).
     */
    public static function logBaseCanSkipValueErrorGuard(Variable $base): bool
    {
        $b = self::compileTimeNumericScalar($base);
        if (null === $b) {
            return false;
        }
        // NAN is not ≤ 0 in php-src; finite ≤0 must keep the ValueError branch.
        if ($b !== $b) {
            return true;
        }

        return $b > 0.0;
    }

    /**
     * Skip the LLVM {@code PHP_INT_MIN}/{-1} branch when the divisor cannot be
     * {@code -1}, or both operands are compile-time longs that are not that pair.
     */
    public static function intdivCanSkipIntMinNegOneGuard(Variable $dividend, Variable $divisor): bool
    {
        $d = self::compileTimeLongScalar($divisor);
        if (null === $d) {
            return false;
        }
        if (-1 !== $d) {
            return true;
        }
        $n = self::compileTimeLongScalar($dividend);

        return null !== $n && \PHP_INT_MIN !== $n;
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
