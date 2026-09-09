<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Runtime-info / process / clock / civil-date / randmax no-throw proofs
 * for {@see NoThrowCallElision} (#36403 / #36386).
 *
 * Extracted so gen-0 spine gets another TU and the NoThrow hub can ratchet
 * under the size-budget gate. External call sites keep using
 * {@code NoThrowCallElision::…} (trait methods on the hub class).
 *
 * Used via {@code use NoThrowCallElisionRuntimeInfoOps;} on
 * {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: ext/standard/basic_functions.c, info.c, microtime.c,
 * hrtime.c, datetime.c; Zend/zend.c; ext/date/php_date.c; ext/random/random.c.
 */
trait NoThrowCallElisionRuntimeInfoOps
{
    /**
     * Zero-arg declaration-table / SAPI identity reads — php-src
     * {@code ext/standard/basic_functions.c} ({@code get_declared_classes}/
     * {@code get_declared_interfaces}/{@code get_declared_traits}/
     * {@code get_included_files}/{@code get_required_files}),
     * {@code ext/standard/info.c} ({@code php_sapi_name}),
     * {@code Zend/zend.c} ({@code zend_version}). Excess argc is
     * {@code ArgumentCountError}. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureZeroArgRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'get_declared_classes':
            case 'get_declared_interfaces':
            case 'get_declared_traits':
            case 'get_included_files':
            case 'get_required_files':
            case 'php_sapi_name':
            case 'zend_version':
                return true;
            default:
                return false;
        }
    }

    /**
     * Declaration / extension table materializers with optional Z_PARAM_BOOL —
     * php-src {@code ext/standard/basic_functions.c} ({@code get_defined_constants}/
     * {@code get_defined_functions}), {@code ext/standard/info.c}
     * ({@code get_loaded_extensions}). Soft-null bool deprecates; excess argc is
     * {@code ArgumentCountError}. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureDefinedTableRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'get_loaded_extensions':
            case 'get_defined_constants':
            case 'get_defined_functions':
                return true;
            default:
                return false;
        }
    }

    /**
     * Process / script identity reads — php-src {@code ext/standard/info.c}
     * ({@code phpversion}/{@code php_uname}), {@code ext/standard/basic_functions.c}
     * ({@code getmypid}/{@code getmyuid}/{@code getmygid}/{@code getmyinode}/
     * {@code getlastmod}/{@code get_current_user}). Excess argc is
     * {@code ArgumentCountError}. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureProcessIdentityBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'phpversion':
            case 'php_uname':
            case 'getmypid':
            case 'getmyuid':
            case 'getmygid':
            case 'getmyinode':
            case 'getlastmod':
            case 'get_current_user':
                return true;
            default:
                return false;
        }
    }

    /**
     * Memory / ini / GC introspection reads — php-src {@code Zend/zend_alloc.c}
     * ({@code memory_get_usage}/{@code memory_get_peak_usage}),
     * {@code ext/standard/basic_functions.c} ({@code php_ini_loaded_file}/
     * {@code php_ini_scanned_files}/{@code gc_enabled}). Soft-null bool
     * deprecates / TypeErrors; excess argc is {@code ArgumentCountError}.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureMemoryIniRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'memory_get_usage':
            case 'memory_get_peak_usage':
            case 'php_ini_loaded_file':
            case 'php_ini_scanned_files':
            case 'gc_enabled':
                return true;
            default:
                return false;
        }
    }

    /**
     * Zero-arg env / cwd / include_path / output-buffer / connection / session /
     * locale / GC-status reads — php-src {@code ext/standard/file.c}
     * ({@code sys_get_temp_dir}), {@code ext/standard/dir.c} ({@code getcwd}),
     * {@code ext/standard/basic_functions.c} ({@code get_include_path}/
     * {@code connection_status}/{@code connection_aborted}),
     * {@code ext/standard/output.c} ({@code ob_get_level}),
     * {@code ext/session/session.c} ({@code session_status}),
     * {@code ext/standard/locale.c} ({@code localeconv}),
     * {@code Zend/zend_builtin_functions.c} ({@code gc_status}). Excess argc is
     * {@code ArgumentCountError}. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureEnvPathRequestRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'sys_get_temp_dir':
            case 'getcwd':
            case 'get_include_path':
            case 'ob_get_level':
            case 'connection_status':
            case 'connection_aborted':
            case 'session_status':
            case 'localeconv':
            case 'gc_status':
                return true;
            default:
                return false;
        }
    }

    /**
     * Host / last-error / rusage / hash-algo table / OB buffer / pending-header
     * reads — php-src {@code ext/standard/basic_functions.c}
     * ({@code gethostname}/{@code getrusage}/{@code error_get_last}),
     * {@code ext/hash/hash.c} ({@code hash_algos}/{@code hash_hmac_algos}),
     * {@code ext/standard/output.c} ({@code ob_get_contents}/
     * {@code ob_get_length}), {@code ext/standard/head.c}
     * ({@code headers_list}). Excess argc is {@code ArgumentCountError}.
     * Soft-null {@code getrusage} mode deprecates. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureHostErrorHashObRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'gethostname':
            case 'error_get_last':
            case 'getrusage':
            case 'hash_algos':
            case 'hash_hmac_algos':
            case 'ob_get_contents':
            case 'ob_get_length':
            case 'headers_list':
                return true;
            default:
                return false;
        }
    }

    /**
     * JSON/PCRE last-error, default timezone, tzdata version, stream registry,
     * CLI title reads — php-src {@code ext/json/json.c}, {@code ext/pcre/php_pcre.c},
     * {@code ext/date/php_date.c}, {@code ext/standard/streamsfuncs.c},
     * {@code ext/standard/cli_ops.c}. Excess argc is {@code ArgumentCountError}.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureJsonPregTzStreamCliRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'json_last_error':
            case 'json_last_error_msg':
            case 'preg_last_error':
            case 'preg_last_error_msg':
            case 'date_default_timezone_get':
            case 'timezone_version_get':
            case 'stream_get_wrappers':
            case 'stream_get_transports':
            case 'stream_get_filters':
            case 'cli_get_process_title':
                return true;
            default:
                return false;
        }
    }

    /**
     * Date/OB/HTTP/SPL/time introspection getters — php-src
     * {@code ext/date/php_date.c} ({@code timezone_abbreviations_list}/
     * {@code timezone_identifiers_list}/{@code date_get_last_errors}/
     * {@code time}), {@code ext/standard/output.c} ({@code ob_list_handlers}),
     * {@code ext/standard/http.c} ({@code http_get_last_response_headers}),
     * {@code ext/spl/php_spl.c} ({@code spl_autoload_functions}),
     * {@code ext/standard/basic_functions.c} ({@code error_reporting}/
     * {@code ignore_user_abort}), {@code ext/standard/head.c}
     * ({@code http_response_code}/{@code headers_sent}). Setter / by-ref
     * forms stay live. Excess argc is {@code ArgumentCountError}. Soft-null
     * {@code timezone_identifiers_list} group deprecates. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureDateObHttpSplTimeGetterRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'timezone_abbreviations_list':
            case 'timezone_identifiers_list':
            case 'ob_list_handlers':
            case 'date_get_last_errors':
            case 'http_get_last_response_headers':
            case 'spl_autoload_functions':
            case 'time':
            case 'error_reporting':
            case 'ignore_user_abort':
            case 'http_response_code':
            case 'headers_sent':
                return true;
            default:
                return false;
        }
    }

    /**
     * Clock getters — php-src {@code ext/standard/microtime.c}
     * ({@code microtime}/{@code gettimeofday}), {@code ext/standard/hrtime.c}
     * ({@code hrtime}). Arity 0 or typed bool flag; soft-null bool deprecates;
     * excess argc is {@code ArgumentCountError}. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureClockGetterRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'microtime':
            case 'hrtime':
            case 'gettimeofday':
                return true;
            default:
                return false;
        }
    }

    /**
     * Civil date getters — php-src {@code ext/date/php_date.c}
     * ({@code getdate}/{@code idate}), {@code ext/standard/datetime.c}
     * ({@code localtime}). Soft-null timestamp / format deprecates; idate
     * warns on non-one-char / unrecognized format; excess argc is
     * {@code ArgumentCountError}. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureCivilDateGetterRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'getdate':
            case 'localtime':
            case 'idate':
                return true;
            default:
                return false;
        }
    }

    /**
     * MT rand upper-bound constants — php-src {@code ext/random/random.c}
     * ({@code getrandmax}/{@code mt_getrandmax}). Arity 0 only; excess argc is
     * {@code ArgumentCountError}. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureRandmaxRuntimeInfoBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'getrandmax':
            case 'mt_getrandmax':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/versioning.c} {@code version_compare}. Public
     * for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureVersionCompareBuiltin(string $nameLc): bool
    {
        return 'version_compare' === $nameLc;
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function baseConvertArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'decbin':
            case 'dechex':
            case 'decoct':
                // Z_PARAM_LONG — soft-null deprecates (stay live).
                return !isset($callArgs[1])
                    && self::numericParamBuiltinArgCannotThrow($callArgs[0]);
            case 'bindec':
            case 'hexdec':
            case 'octdec':
                // Z_PARAM_STR — soft-null / __toString stay live.
                return !isset($callArgs[1])
                    && self::stringParamBuiltinArgCannotThrow($callArgs[0]);
            case 'base_convert':
                // string, long from_base, long to_base — ValueError when bases
                // outside [2,36]; only compile-time bases in range prove.
                if (
                    !isset($callArgs[1], $callArgs[2])
                    || isset($callArgs[3])
                    || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }

                return self::compileTimeRadixBaseInRange($callArgs[1])
                    && self::compileTimeRadixBaseInRange($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function inetArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[1])) {
            return false;
        }
        switch ($nameLc) {
            case 'ip2long':
            case 'inet_pton':
            case 'inet_ntop':
                // Z_PARAM_STR — soft-null / __toString stay live.
                return self::stringParamBuiltinArgCannotThrow($callArgs[0]);
            case 'long2ip':
                // Z_PARAM_LONG — soft-null deprecates (stay live).
                return self::numericParamBuiltinArgCannotThrow($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Typed numeric scalars only (≥1 for min/max, ≥2 for fmin/fmax). Array-form
     * {@code min}/{@code max} (single hashtable / native array) stays out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function minMaxArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if ([] === $callArgs) {
            return false;
        }
        if (('fmin' === $nameLc || 'fmax' === $nameLc) && \count($callArgs) < 2) {
            return false;
        }
        // Single array argument → php_min_max over elements (object handlers).
        if (1 === \count($callArgs) && self::typedArrayArgCannotThrow($callArgs[0])) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::numericParamBuiltinArgCannotThrow($arg)) {
                return false;
            }
            // Soft-null numeric params deprecate — stay conservative for no-throw
            // (peer math discarded elision excludes TYPE_NULL).
            if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exactly three typed numeric args — soft-null / value-box stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function checkdateArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1], $callArgs[2])
            || isset($callArgs[3])
        ) {
            return false;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::numericParamBuiltinArgCannotThrow($arg)) {
                return false;
            }
            if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Exactly two typed / literal strings — TypeError / soft-null stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function hashEqualsArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
        ) {
            return false;
        }

        return self::stringParamBuiltinArgCannotThrow($callArgs[0])
            && self::stringParamBuiltinArgCannotThrow($callArgs[1]);
    }

    /**
     * Typed / literal string path + optional typed numeric flags — soft-null
     * path/flags stay out (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function pathinfoArgsCannotThrow(array $callArgs): bool
    {
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
            || $callArgs[1]->isNullConstant
            || Variable::TYPE_NULL === $callArgs[1]->type
            || isset($callArgs[2])
        ) {
            return false;
        }

        return true;
    }

    /**
     * Typed / literal string url + optional typed numeric component — soft-null
     * url/component stay out (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function parseUrlArgsCannotThrow(array $callArgs): bool
    {
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
            || $callArgs[1]->isNullConstant
            || Variable::TYPE_NULL === $callArgs[1]->type
            || isset($callArgs[2])
        ) {
            return false;
        }

        return true;
    }

    /**
     * Exactly one typed / literal string — soft-null / {@code __toString} stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function functionExistsArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || isset($callArgs[1])
        ) {
            return false;
        }

        return self::stringParamBuiltinArgCannotThrow($callArgs[0]);
    }

    /**
     * Exactly one typed / literal string — soft-null / {@code __toString} stay out.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function extensionLoadedArgsCannotThrow(array $callArgs): bool
    {
        return self::functionExistsArgsCannotThrow($callArgs);
    }

    /**
     * Exactly one typed / literal string — soft-null stays out (deprecate).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function definedArgsCannotThrow(array $callArgs): bool
    {
        return self::functionExistsArgsCannotThrow($callArgs);
    }

    /**
     * Typed object + typed / literal method string — soft-null method deprecates;
     * string class names / value-box receivers stay out (autoload / TypeError).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function methodExistsArgsCannotThrow(array $callArgs): bool
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

        return self::stringParamBuiltinArgCannotThrow($callArgs[1]);
    }

    /**
     * Typed object + typed / literal property string — peer
     * {@see methodExistsArgsCannotThrow}.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function propertyExistsArgsCannotThrow(array $callArgs): bool
    {
        return self::methodExistsArgsCannotThrow($callArgs);
    }

    /**
     * Typed / literal class name + compile-time-false {@code $autoload}.
     * Soft-null name/autoload stay out (deprecate). Missing / true / dynamic
     * autoload stay out (spl_autoload side effects).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function classExistsFamilyArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || isset($callArgs[2])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
        ) {
            return false;
        }

        return self::isCompileTimeFalseAutoloadArg($callArgs[1]);
    }

    /**
     * Exactly one typed object — zero-arg {@code get_class}/{@code get_parent_class}
     * deprecate / need scope; string {@code get_parent_class} autoloads; soft-null
     * / value-box stay out ({@code TypeError}).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function objectIntrospectArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || isset($callArgs[1])
            || !$callArgs[0] instanceof Variable
        ) {
            return false;
        }

        return Variable::TYPE_OBJECT === $callArgs[0]->type;
    }

    /**
     * Typed object + typed / literal class string + optional non-null bool-ish
     * {@code $allow_string}. Soft-null class / allow_string deprecate; string /
     * value-box subjects stay out (autoload / handlers).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function isAFamilyArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || Variable::TYPE_OBJECT !== $callArgs[0]->type
        ) {
            return false;
        }
        // Z_PARAM_STR class — soft-null deprecates (#29817); require typed /
        // literal string (not int/bool/null soft-coercion).
        $class = $callArgs[1];
        if ($class->isNullConstant || Variable::TYPE_NULL === $class->type) {
            return false;
        }
        if (
            null === JitStringArg::compileTimeLiteral($class)
            && Variable::TYPE_STRING !== $class->type
        ) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return !isset($callArgs[3]);
        }
        if (!$callArgs[2] instanceof Variable || isset($callArgs[3])) {
            return false;
        }
        // Z_PARAM_BOOL — soft-null deprecates (#31339); objects/value-box may
        // __toString / handlers. Typed bool / long / compile-time 0|1 only.
        $allow = $callArgs[2];
        if ($allow->isNullConstant || Variable::TYPE_NULL === $allow->type) {
            return false;
        }
        if (Variable::TYPE_NATIVE_BOOL === $allow->type || Variable::TYPE_NATIVE_LONG === $allow->type) {
            return true;
        }

        return null !== $allow->compileTimeLong;
    }

    /**
     * Typed object (+ optional non-null bool-ish {@code $autoload}). Soft-null
     * autoload deprecates; string / value-box subjects stay out (autoload /
     * handlers). Object subjects never autoload — the class is already loaded.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function classHierarchyArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || Variable::TYPE_OBJECT !== $callArgs[0]->type
        ) {
            return false;
        }
        if (!isset($callArgs[1])) {
            return !isset($callArgs[2]);
        }
        if (!$callArgs[1] instanceof Variable || isset($callArgs[2])) {
            return false;
        }
        // Z_PARAM_BOOL autoload — soft-null deprecates; objects/value-box may
        // __toString / handlers. Typed bool / long / compile-time 0|1 only.
        $autoload = $callArgs[1];
        if ($autoload->isNullConstant || Variable::TYPE_NULL === $autoload->type) {
            return false;
        }
        if (
            Variable::TYPE_NATIVE_BOOL === $autoload->type
            || Variable::TYPE_NATIVE_LONG === $autoload->type
        ) {
            return true;
        }

        return null !== $autoload->compileTimeLong;
    }

    /**
     * Exactly one typed object — peer {@see objectIntrospectArgsCannotThrow}.
     * Soft-null / string / value-box stay out ({@code TypeError} / autoload).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function objectVarsMethodsArgsCannotThrow(array $callArgs): bool
    {
        return self::objectIntrospectArgsCannotThrow($callArgs);
    }

    /**
     * Exactly zero arguments — any argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function zeroArgRuntimeInfoArgsCannotThrow(array $callArgs): bool
    {
        return [] === $callArgs;
    }

    /**
     * Zero args, or one typed bool / long / compile-time 0|1. Soft-null bool
     * deprecates; objects / value-box / strings stay out (coerce / handlers).
     * Excess argc is {@code ArgumentCountError}.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function definedTableRuntimeInfoArgsCannotThrow(array $callArgs): bool
    {
        if ([] === $callArgs) {
            return true;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[1])) {
            return false;
        }
        $flag = $callArgs[0];
        if ($flag->isNullConstant || Variable::TYPE_NULL === $flag->type) {
            return false;
        }
        if (
            Variable::TYPE_NATIVE_BOOL === $flag->type
            || Variable::TYPE_NATIVE_LONG === $flag->type
        ) {
            return true;
        }

        return null !== $flag->compileTimeLong;
    }

    /**
     * {@code getmypid}/{@code getmyuid}/{@code getmygid}/{@code getmyinode}/
     * {@code getlastmod}/{@code get_current_user}: arity 0.
     * {@code phpversion}/{@code php_uname}: arity 0 or one string-coercible
     * arg (soft-null / scalars do not leave throw-pending; objects /
     * value-box stay out). Excess argc is {@code ArgumentCountError}.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function processIdentityArgsCannotThrow(string $nameLc, array $callArgs): bool
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
                if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable || isset($callArgs[1])) {
                    return false;
                }

                return self::stringParamBuiltinArgCannotThrow($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * {@code php_ini_loaded_file}/{@code php_ini_scanned_files}/{@code gc_enabled}:
     * arity 0. {@code memory_get_usage}/{@code memory_get_peak_usage}: arity 0
     * or one non-null bool/long (soft-null deprecates / TypeError under
     * strict_types). Excess argc is {@code ArgumentCountError}.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function memoryIniRuntimeInfoArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        switch ($nameLc) {
            case 'php_ini_loaded_file':
            case 'php_ini_scanned_files':
            case 'gc_enabled':
                return [] === $callArgs;
            case 'memory_get_usage':
            case 'memory_get_peak_usage':
                return self::definedTableRuntimeInfoArgsCannotThrow($callArgs);
            default:
                return false;
        }
    }

    /**
     * Exactly zero arguments — peer {@see zeroArgRuntimeInfoArgsCannotThrow}.
     * Excess argc is {@code ArgumentCountError}.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function envPathRequestRuntimeInfoArgsCannotThrow(array $callArgs): bool
    {
        return self::zeroArgRuntimeInfoArgsCannotThrow($callArgs);
    }

    /**
     * {@code gethostname}/{@code error_get_last}/{@code hash_algos}/
     * {@code hash_hmac_algos}/{@code ob_get_contents}/{@code ob_get_length}/
     * {@code headers_list}: arity 0. {@code getrusage}: arity 0 or one
     * numeric scalar (soft-null deprecates but does not throw). Excess argc is
     * {@code ArgumentCountError}. Public for {@see DiscardedPureCallElision}
     * (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function hostErrorHashObRuntimeInfoArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        switch ($nameLc) {
            case 'gethostname':
            case 'error_get_last':
            case 'hash_algos':
            case 'hash_hmac_algos':
            case 'ob_get_contents':
            case 'ob_get_length':
            case 'headers_list':
                return self::zeroArgRuntimeInfoArgsCannotThrow($callArgs);
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

                // Soft-null deprecates (no throw) — still "cannot throw".
                return self::intParamBuiltinArgCannotThrow($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * Exactly zero arguments — peer {@see zeroArgRuntimeInfoArgsCannotThrow}.
     * Excess argc is {@code ArgumentCountError}. Public for
     * {@see DiscardedPureCallElision} (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function jsonPregTzStreamCliRuntimeInfoArgsCannotThrow(array $callArgs): bool
    {
        return self::zeroArgRuntimeInfoArgsCannotThrow($callArgs);
    }

    /**
     * {@code timezone_abbreviations_list}/{@code ob_list_handlers}/
     * {@code date_get_last_errors}/{@code http_get_last_response_headers}/
     * {@code spl_autoload_functions}/{@code time}: arity 0.
     * {@code error_reporting}/{@code ignore_user_abort}/{@code http_response_code}/
     * {@code headers_sent}: arity 0 only (setter / by-ref forms stay out).
     * {@code timezone_identifiers_list}: arity 0 or one typed long group
     * (soft-null deprecates; country-code form stays out — ValueError).
     * Public for {@see DiscardedPureCallElision} (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function dateObHttpSplTimeGetterRuntimeInfoArgsCannotThrow(
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
                return self::zeroArgRuntimeInfoArgsCannotThrow($callArgs);
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

                return self::numericParamBuiltinArgCannotThrow($callArgs[0]);
            default:
                return false;
        }
    }

    /**
     * {@code microtime}/{@code hrtime}/{@code gettimeofday}: arity 0 or one
     * typed bool/numeric flag (soft-null deprecates). Public for
     * {@see DiscardedPureCallElision} (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function clockGetterRuntimeInfoArgsCannotThrow(array $callArgs): bool
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

        return self::numericParamBuiltinArgCannotThrow($callArgs[0]);
    }

    /**
     * {@code getdate}: arity 0 or one typed/long-coercible timestamp.
     * {@code localtime}: arity 0..2 (timestamp + optional associative bool).
     * {@code idate}: format string proven as a valid one-char token, plus
     * optional typed timestamp. Soft-null args do not throw (deprecate /
     * warning paths stay observable for discarded elision separately).
     * Public for {@see DiscardedPureCallElision} (#36386).
     *
     * @param array<int, Variable> $callArgs
     */
    public static function civilDateGetterRuntimeInfoArgsCannotThrow(
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

                return self::numericParamBuiltinArgCannotThrow($callArgs[0]);
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
                if (!self::numericParamBuiltinArgCannotThrow($callArgs[0])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[1]);
            case 'idate':
                if (
                    !isset($callArgs[0])
                    || !$callArgs[0] instanceof Variable
                    || isset($callArgs[2])
                ) {
                    return false;
                }
                if (!self::idateFormatArgCannotThrow($callArgs[0])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[1]);
            default:
                return false;
        }
    }

    /**
     * Compile-time one-char idate format that php-src {@code php_idate()}
     * accepts without warning ({@code ext/date/php_date.c}).
     */
    public static function isValidIdateFormatLiteral(string $format): bool
    {
        if (1 !== \strlen($format)) {
            return false;
        }
        switch ($format) {
            case 'B':
            case 'd':
            case 'j':
            case 'h':
            case 'g':
            case 'H':
            case 'i':
            case 'I':
            case 'L':
            case 'm':
            case 'n':
            case 'N':
            case 's':
            case 't':
            case 'U':
            case 'w':
            case 'W':
            case 'y':
            case 'Y':
            case 'z':
            case 'o':
                return true;
            default:
                return false;
        }
    }

    /**
     * @param Variable $arg
     */
    private static function idateFormatArgCannotThrow(Variable $arg): bool
    {
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null === $lit) {
            // Soft-null / typed string may warn at runtime — still no user
            // throw-pending for NoThrow instrumentation.
            return self::stringParamBuiltinArgCannotThrow($arg)
                || $arg->isNullConstant
                || Variable::TYPE_NULL === $arg->type;
        }

        return self::isValidIdateFormatLiteral($lit);
    }

}
