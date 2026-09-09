<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Pure-builtin call-site arg proofs for no-throw elision (#36387).
 *
 * Extracted from {@see NoThrowCallElision} so the hub stays under its
 * size-budget target. Dispatches {@code pureBuiltinArgsAreNoThrow} across
 * type/ctype/string/math/exists/runtime-info predicates (peer traits) and
 * owns the shared scalar/array arg helpers used by those peers.
 *
 * Used via {@code use NoThrowCallElisionPureBuiltinArgOps;} on
 * {@see NoThrowCallElision}.
 *
 * No new C ABI. php-src: ext/standard/{type,string,math,basic_functions,
 * url,file,info,html,md5,crc32,base64}.c; ext/ctype; Zend/zend_builtin_functions.c;
 * ext/hash; ext/date; ext/spl — same surfaces as the peer NoThrow proof traits.
 */
trait NoThrowCallElisionPureBuiltinArgOps
{

    /**
     * Builtins that never set user throw-pending when args cannot run user code.
     *
     * TypeError paths for known-bad compile-time types abort inside the builtin
     * ({@see \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort}); the
     * caller's {@code phpc_jit_has_throw_pending} check is only needed when
     * {@code __toString} (or similar) may throw — i.e. object / value-box args.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function pureBuiltinArgsAreNoThrow(Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (self::isPureTypePredicateBuiltin($name)) {
            // is_int / is_string / gettype / get_debug_type / … never invoke
            // user handlers (php-src type.c / basic_functions.c). Exclude
            // is_callable / is_a (autoload / __invoke).
            return true;
        }
        if ('pi' === $name) {
            // math.c pi() — no args, constant only.
            return [] === $callArgs;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (self::isPureCtypeBuiltin($name)) {
            // php-src ext/ctype/ctype.c — string args only inspect bytes; int/null
            // deprecate but never leave user throw-pending; object/value-box may
            // __toString (peer strlen / string transforms).
            return self::stringParamBuiltinArgCannotThrow($callArgs[0]);
        }
        if ('strlen' === $name || 'ord' === $name) {
            // Z_PARAM_STR family — __toString only on object / value-box.
            return self::stringParamBuiltinArgCannotThrow($callArgs[0]);
        }
        if (self::isPureStringTransformBuiltin($name)) {
            // Mixed STR + optional LONG/BOOL (md5 binary, dirname levels, …).
            return self::stringTransformArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureHtmlEscapeBuiltin($name)) {
            // html.c / string.c / exec.c — mixed STR + LONG/BOOL; encoding may be null.
            return self::htmlEscapeArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureStringSliceOrCompareBuiltin($name)) {
            // Mixed Z_PARAM_STR + Z_PARAM_LONG (substr/str_repeat/strncmp) or
            // all-string compares/searches — numeric slots never invoke
            // __toString; object/value-box string slots stay conservative.
            return self::stringSliceOrCompareArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureStringPadOrSplitBuiltin($name)) {
            // str_pad / chunk_split / wordwrap / str_split / explode / str_getcsv —
            // STR + LONG/BOOL slots; object/value-box string args stay conservative.
            // str_getcsv requires all four strings (omitted $escape DEP stays live).
            return self::stringPadOrSplitArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureStringReplaceOrJoinBuiltin($name)) {
            // str_replace / str_ireplace / substr_replace / strtr (3-string) —
            // typed string (+ numeric offset) only; array forms / &$count stay out.
            return self::stringReplaceOrJoinArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureNumberFormatBuiltin($name)) {
            // number_format.c — numeric + optional decimals + nullable separators.
            return self::numberFormatArgsCannotThrow($callArgs);
        }
        if (self::isPureScalarCastBuiltin($name)) {
            // type.c / basic_functions.c intval/floatval/boolval/strval — typed
            // scalars only; objects stay out (__toString / cast handlers).
            return self::scalarCastArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureBaseConvertBuiltin($name)) {
            // math.c decbin/hexdec/base_convert — typed numeric or string; soft-null
            // stays out; base_convert bases must be compile-time [2,36].
            return self::baseConvertArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureInetBuiltin($name)) {
            // basic_functions.c ip2long/long2ip/inet_pton/inet_ntop — typed
            // string or numeric.
            return self::inetArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureMinMaxBuiltin($name)) {
            // array.c min/max + math.c fmin/fmax — typed numerics only; single
            // array-form min/max stays out (element compare / object handlers).
            return self::minMaxArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureCheckdateBuiltin($name)) {
            // datetime.c checkdate — three Z_PARAM_LONG; soft-null deprecates.
            return self::checkdateArgsCannotThrow($callArgs);
        }
        if (self::isPureHashEqualsBuiltin($name)) {
            // hash.c hash_equals — two typed strings; TypeError on non-string.
            return self::hashEqualsArgsCannotThrow($callArgs);
        }
        if (self::isPurePathinfoBuiltin($name)) {
            // basic_functions.c / file.c pathinfo — typed string + optional flags.
            return self::pathinfoArgsCannotThrow($callArgs);
        }
        if (self::isPureParseUrlBuiltin($name)) {
            // url.c parse_url — typed string + optional component long.
            return self::parseUrlArgsCannotThrow($callArgs);
        }
        if (self::isPureFunctionExistsBuiltin($name)) {
            // zend_builtin_functions.c function_exists — typed string; no autoload.
            return self::functionExistsArgsCannotThrow($callArgs);
        }
        if (self::isPureExtensionLoadedBuiltin($name)) {
            // info.c extension_loaded — typed string; table lookup only.
            return self::extensionLoadedArgsCannotThrow($callArgs);
        }
        if (self::isPureDefinedBuiltin($name)) {
            // basic_functions.c defined — typed string; constant table only.
            return self::definedArgsCannotThrow($callArgs);
        }
        if (self::isPureMethodExistsBuiltin($name)) {
            // zend_builtin_functions.c method_exists — typed object + string method;
            // string class names stay out (autoload can throw).
            return self::methodExistsArgsCannotThrow($callArgs);
        }
        if (self::isPurePropertyExistsBuiltin($name)) {
            // zend_builtin_functions.c property_exists — typed object + string
            // property; string class names stay out (autoload can throw).
            return self::propertyExistsArgsCannotThrow($callArgs);
        }
        if (self::isPureArrayKeyExistsBuiltin($name)) {
            // array.c array_key_exists/key_exists — typed array + non-null scalar key.
            return self::arrayKeyExistsArgsCannotThrow($callArgs);
        }
        if (self::isPureClassExistsFamilyBuiltin($name)) {
            // zend_builtin_functions.c class_exists / interface_exists / trait_exists /
            // enum_exists — only with compile-time-false $autoload (default true
            // triggers spl_autoload / can throw).
            return self::classExistsFamilyArgsCannotThrow($callArgs);
        }
        if (self::isPureObjectIntrospectBuiltin($name)) {
            // get_class / get_parent_class / spl_object_id / spl_object_hash —
            // typed object only; string get_parent_class autoloads; soft-null
            // TypeErrors stay out.
            return self::objectIntrospectArgsCannotThrow($callArgs);
        }
        if (self::isPureIsAFamilyBuiltin($name)) {
            // is_a / is_subclass_of — typed object + string class; string
            // subjects stay out (autoload when allow_string). Soft-null
            // class / allow_string deprecate.
            return self::isAFamilyArgsCannotThrow($callArgs);
        }
        if (self::isPureClassHierarchyBuiltin($name)) {
            // class_parents / class_implements / class_uses — typed object
            // subject only; string subjects stay out (autoload). Soft-null
            // $autoload deprecates.
            return self::classHierarchyArgsCannotThrow($callArgs);
        }
        if (self::isPureObjectVarsMethodsBuiltin($name)) {
            // get_object_vars / get_mangled_object_vars / get_class_methods —
            // typed object only; string get_class_methods stays out (autoload).
            return self::objectVarsMethodsArgsCannotThrow($callArgs);
        }
        if (self::isPureZeroArgRuntimeInfoBuiltin($name)) {
            // get_declared_* / get_included_files / php_sapi_name / zend_version —
            // arity 0 only; excess args are ArgumentCountError.
            return self::zeroArgRuntimeInfoArgsCannotThrow($callArgs);
        }
        if (self::isPureDefinedTableRuntimeInfoBuiltin($name)) {
            // get_loaded_extensions / get_defined_constants / get_defined_functions —
            // arity 0 or one non-null bool; soft-null deprecates; excess argc
            // is ArgumentCountError.
            return self::definedTableRuntimeInfoArgsCannotThrow($callArgs);
        }
        if (self::isPureProcessIdentityBuiltin($name)) {
            // phpversion / php_uname / getmypid / getmyuid / getmygid /
            // getmyinode / getlastmod / get_current_user — info.c /
            // basic_functions.c process / script identity reads; excess argc
            // is ArgumentCountError.
            return self::processIdentityArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureMemoryIniRuntimeInfoBuiltin($name)) {
            // memory_get_usage / memory_get_peak_usage / php_ini_loaded_file /
            // php_ini_scanned_files / gc_enabled — introspection reads; soft-null
            // bool deprecates / TypeErrors; excess argc is ArgumentCountError.
            return self::memoryIniRuntimeInfoArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureEnvPathRequestRuntimeInfoBuiltin($name)) {
            // sys_get_temp_dir / getcwd / get_include_path / ob_get_level /
            // connection_status / connection_aborted / session_status /
            // localeconv / gc_status — arity 0 only; excess args are
            // ArgumentCountError.
            return self::envPathRequestRuntimeInfoArgsCannotThrow($callArgs);
        }
        if (self::isPureHostErrorHashObRuntimeInfoBuiltin($name)) {
            // gethostname / error_get_last / getrusage / hash_algos /
            // hash_hmac_algos / ob_get_contents / ob_get_length /
            // headers_list — arity 0 (getrusage: optional typed long);
            // soft-null getrusage mode deprecates; excess argc is
            // ArgumentCountError.
            return self::hostErrorHashObRuntimeInfoArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureJsonPregTzStreamCliRuntimeInfoBuiltin($name)) {
            // json_last_error* / preg_last_error* / date_default_timezone_get /
            // timezone_version_get / stream_get_{wrappers,transports,filters} /
            // cli_get_process_title — arity 0 only; excess argc is
            // ArgumentCountError.
            return self::jsonPregTzStreamCliRuntimeInfoArgsCannotThrow($callArgs);
        }
        if (self::isPureDateObHttpSplTimeGetterRuntimeInfoBuiltin($name)) {
            // timezone_abbreviations_list / timezone_identifiers_list /
            // ob_list_handlers / date_get_last_errors /
            // http_get_last_response_headers / spl_autoload_functions / time /
            // error_reporting / ignore_user_abort / http_response_code /
            // headers_sent — arity 0 (timezone_identifiers_list: optional typed
            // long group); setter / by-ref forms stay out; soft-null group
            // deprecates; excess argc is ArgumentCountError.
            return self::dateObHttpSplTimeGetterRuntimeInfoArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureClockGetterRuntimeInfoBuiltin($name)) {
            // microtime / hrtime / gettimeofday — arity 0 or typed bool;
            // soft-null bool deprecates; excess argc is ArgumentCountError.
            return self::clockGetterRuntimeInfoArgsCannotThrow($callArgs);
        }
        if (self::isPureCivilDateGetterRuntimeInfoBuiltin($name)) {
            // getdate / localtime / idate — optional typed timestamp; idate
            // needs a proven one-char format; soft-null deprecates; excess
            // argc is ArgumentCountError.
            return self::civilDateGetterRuntimeInfoArgsCannotThrow($name, $callArgs);
        }
        if (self::isPureRandmaxRuntimeInfoBuiltin($name)) {
            // getrandmax / mt_getrandmax — arity 0 only.
            return self::zeroArgRuntimeInfoArgsCannotThrow($callArgs);
        }
        if (self::isPureVersionCompareBuiltin($name)) {
            // versioning.c — typed strings; optional operator must be proven valid.
            return self::versionCompareArgsCannotThrow($callArgs);
        }
        if ('chr' === $name) {
            // Z_PARAM_LONG family — object→int does not call __toString; still
            // keep value-box / object conservative (coercion paths vary).
            return self::intParamBuiltinArgCannotThrow($callArgs[0]);
        }
        if ('count' === $name || 'sizeof' === $name) {
            // Countable::count() is user code — only typed arrays are no-throw.
            if (!self::typedArrayArgCannotThrow($callArgs[0])) {
                return false;
            }
            if (isset($callArgs[1])) {
                return $callArgs[1] instanceof Variable
                    && self::numericParamBuiltinArgCannotThrow($callArgs[1]);
            }

            return true;
        }
        if (self::isPureMathBuiltin($name)) {
            // Z_PARAM_DOUBLE / LONG family — domain errors yield NAN/INF, not user
            // throw-pending (php-src math.c). Value-box / object stay conservative.
            // Multi-arg (hypot/fmod/…) must prove every numeric param (#36386).
            foreach ($callArgs as $arg) {
                if (!$arg instanceof Variable || !self::numericParamBuiltinArgCannotThrow($arg)) {
                    return false;
                }
            }

            return true;
        }
        if ('intdiv' === $name) {
            // math.c intdiv — DivisionByZeroError / ArithmeticError stay live
            // unless the divisor (and INT_MIN/-1 pair) are compile-time proven
            // safe and both args are already numeric (#36386 / peer discarded).
            return DiscardedPureCallElision::intdivArgsCannotThrow($callArgs);
        }
        if ('str_increment' === $name || 'str_decrement' === $name) {
            // string.c str_increment/str_decrement — ValueError stays live
            // unless the arg is a compile-time ASCII-alphanumeric literal
            // proven safe (#36386 / peer discarded #37168).
            return DiscardedPureCallElision::strIncDecArgsCannotThrow($name, $callArgs);
        }

        return false;
    }

    /**
     * True when a math builtin arg cannot leave user throw-pending (native /
     * compile-time numeric scalars only).
     */
    private static function numericParamBuiltinArgCannotThrow(Variable $arg): bool
    {
        return self::intParamBuiltinArgCannotThrow($arg);
    }

    /**
     * Typed hashtable / packed native array — no Countable::count() user handler
     * (php-src Zend/zend_builtin_functions.c PHP_FUNCTION(count)).
     */
    private static function typedArrayArgCannotThrow(Variable $arg): bool
    {
        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            return true;
        }

        return Variable::TYPE_HASHTABLE === $arg->type;
    }

    /**
     * True when strlen/ord($arg) cannot invoke {@code __toString} or leave user
     * throw-pending for the caller to observe.
     */
    private static function stringParamBuiltinArgCannotThrow(Variable $arg): bool
    {
        if (null !== JitStringArg::compileTimeLiteral($arg)) {
            return true;
        }
        // Native __string__* — already a string; no coercion / __toString.
        if (Variable::TYPE_STRING === $arg->type) {
            return true;
        }
        // Scalar coercions (int/float/bool) never throw; null soft-coerces or
        // TypeErrors via abort, not user throw-pending.
        if (
            Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type
            || Variable::TYPE_NULL === $arg->type
            || $arg->isNullConstant
        ) {
            return true;
        }

        return false;
    }

    /**
     * True when chr($arg) cannot leave user throw-pending (native / compile-time
     * numeric scalars only).
     */
    private static function intParamBuiltinArgCannotThrow(Variable $arg): bool
    {
        if (null !== $arg->compileTimeLong || null !== $arg->compileTimeFloat) {
            return true;
        }
        if (
            Variable::TYPE_NATIVE_LONG === $arg->type
            || Variable::TYPE_NATIVE_DOUBLE === $arg->type
            || Variable::TYPE_NATIVE_BOOL === $arg->type
            || Variable::TYPE_NULL === $arg->type
            || $arg->isNullConstant
        ) {
            return true;
        }
        // Numeric string literals coerce without user code.
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit && is_numeric($lit)) {
            return true;
        }

        return false;
    }
}
