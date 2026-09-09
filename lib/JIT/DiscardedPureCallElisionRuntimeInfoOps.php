<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Discarded pure-call elision for runtime-info / process / clock builtins (#36387).
 *
 * Extracted from {@see DiscardedPureCallElision} so the hub is not one monolith
 * TU on the gen-0 spine (split-TU / size-budget ratchet). Shared arg predicates
 * ({@code stringArgAllowsDiscardedElision}, {@code mathArgAllowsDiscardedElision})
 * stay on the hub class.
 *
 * Used via {@code use DiscardedPureCallElisionRuntimeInfoOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/standard/basic_functions.c, info.c, microtime.c,
 * hrtime.c, datetime.c; Zend/zend.c; ext/date/php_date.c; ext/random/random.c;
 * ext/json, ext/pcre, ext/hash, streams, session, output, SPL autoload getters.
 */
trait DiscardedPureCallElisionRuntimeInfoOps
{
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
}
