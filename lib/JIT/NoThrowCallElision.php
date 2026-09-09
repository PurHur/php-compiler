<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\OpCode;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Call\Vararg;

require_once __DIR__.'/NoThrowCallElisionRuntimeInfoPredicates.php';
require_once __DIR__.'/NoThrowCallElisionExistsConvertAndIntrospectOps.php';
require_once __DIR__.'/NoThrowCallElisionStringOps.php';
require_once __DIR__.'/NoThrowCallElisionMathAndFormatOps.php';
require_once __DIR__.'/NoThrowCallElisionCalleeGraph.php';

/**
 * Skip uncaught-trace frame push/pop and after-call throw-pending checks for
 * user functions whose CFG cannot throw (#36386).
 *
 * php-src always records EG(current_execute_data) frames; when a function body
 * has no {@see OpCode::TYPE_THROW}, no {@see OpCode::TYPE_NEW}, no includes, and
 * only calls to itself or other proven no-throw user functions (leaf recursion
 * like {@code fibo_r}, call chains like {@code top→mid→leaf}, leaf methods
 * like {@code Node::bump}, same-class instance chains like
 * {@code A::top→A::mid→A::leaf}, or same-class static chains like
 * {@code A::top→self::mid→self::leaf}) — the AOT frames would never appear on an
 * uncaught trace — paying {@code phpc_ex_stack_push/pop} +
 * {@code phpc_jit_has_throw_pending} on every edge is pure overhead.
 *
 * Also skips the after-call check for pure builtins when arguments prove they
 * cannot invoke user code or set throw-pending — e.g. {@code strlen('x')} /
 * {@code ord('A')} on a native {@code TYPE_STRING}, {@code chr(65)} on a native
 * long, pure type predicates ({@code is_int} / {@code is_string} / …), string
 * transforms ({@code strtolower} / {@code ucwords} / {@code bin2hex} /
 * {@code urlencode} / {@code str_rot13} / {@code quotemeta} / {@code md5} /
 * {@code crc32} / {@code base64_encode} / {@code soundex} / …), string
 * slice/compare/search ({@code substr} / {@code str_repeat} / {@code strcmp} /
 * {@code strpos} / {@code strstr} / {@code str_contains} /
 * {@code str_starts_with} / {@code str_ends_with} / …), and pure math
 * ({@code sqrt} / {@code abs} / {@code pow} / {@code fdiv} / …) on native
 * numeric scalars, {@code intdiv} when the divisor is a compile-time long
 * proven not to {@code DivisionByZeroError} / {@code ArithmeticError}, and
 * {@code str_increment}/{@code str_decrement} when the arg is a compile-time
 * ASCII-alphanumeric literal proven not to {@code ValueError} (php-src
 * {@code ext/standard/string.c}
 * {@code PHP_FUNCTION(strlen)} / {@code ord} / {@code chr} / {@code ucwords} /
 * {@code substr} / {@code strcmp} / {@code strpos} / {@code str_contains} /
 * {@code str_increment}; {@code ext/standard/url.c} {@code urlencode};
 * {@code ext/standard/crc32.c} / {@code md5.c} / {@code base64.c};
 * {@code ext/standard/type.c} {@code is_*}; {@code ext/standard/math.c}
 * {@code PHP_FUNCTION(sqrt)} / {@code pow} / {@code intdiv} etc.; throwing
 * {@code __toString} needs an object/value box).
 * Discarded calls with the same arg proofs are dropped entirely by
 * {@see DiscardedPureCallElision}.
 *
 * Single-param identity bodies ({@code function id($x){return $x;}}) are also
 * recorded so call sites can replace the call with the compiled argument
 * (user-script AOT skips IR inlining — {@see Context::runModuleOptimizationPasses}).
 *
 * Analyze at enqueue time (before {@see \PHPCompiler\JIT::runQueue}), not only when
 * the body is lowered: `{main}` resolves method calls while callees are still
 * queued, so a body-time record is too late for call-site elision.
 *
 * A fixpoint at the start of {@see refineFixpoint} upgrades callers once their
 * callees become proven (declaration order must not matter).
 *
 * Runtime-info pure-builtin name/arg predicates live in
 * {@see NoThrowCallElisionRuntimeInfoPredicates} (#36387).
 * Exists / convert / introspect / scalar-cast / version_compare proofs live in
 * {@see NoThrowCallElisionExistsConvertAndIntrospectOps} (#36403).
 * Type / ctype / string transform / html / slice / pad / replace proofs live in
 * {@see NoThrowCallElisionStringOps} (#36387).
 * Math / number_format / scalar-cast / base-inet-minmax / path-url
 * proofs live in {@see NoThrowCallElisionMathAndFormatOps} (#36387).
 */
final class NoThrowCallElision
{
    use NoThrowCallElisionRuntimeInfoPredicates;

    use NoThrowCallElisionExistsConvertAndIntrospectOps;

    use NoThrowCallElisionStringOps;
    use NoThrowCallElisionMathAndFormatOps;
    use NoThrowCallElisionCalleeGraph;

    /**
     * Record whether {@code $funcLc} is safe to call without exception-stack /
     * pending-throw instrumentation.
     */
    public static function analyzeAndRecord(Context $context, Block $entry, string $funcLc): void
    {
        $funcLc = strtolower($funcLc);
        if ('' === $funcLc || '{main}' === $funcLc) {
            return;
        }
        $context->noThrowAnalyzeBlocks[$funcLc] = $entry;
        if (Block::isTrivialIdentityCalleeBody($entry)) {
            $context->trivialIdentityUserFunctions[$funcLc] = true;
            // Identity bodies cannot throw and call nothing.
            $context->noThrowUserFunctions[$funcLc] = true;

            return;
        }
        if (!empty($context->noThrowUserFunctions[$funcLc])) {
            return;
        }
        $context->noThrowUserFunctions[$funcLc] = self::bodyIsNoThrowCalleeGraph(
            $entry,
            $funcLc,
            $context
        );
    }

    /**
     * Re-evaluate bodies that failed only because callees were not yet proven.
     * Call once all user functions are enqueued, before lowering call sites.
     */
    public static function refineFixpoint(Context $context): void
    {
        $pending = $context->noThrowAnalyzeBlocks;
        if ([] === $pending) {
            return;
        }
        $limit = count($pending) + 2;
        for ($pass = 0; $pass < $limit; ++$pass) {
            $changed = false;
            foreach ($pending as $funcLc => $entry) {
                if (!empty($context->noThrowUserFunctions[$funcLc])) {
                    continue;
                }
                if (self::bodyIsNoThrowCalleeGraph($entry, $funcLc, $context)) {
                    $context->noThrowUserFunctions[$funcLc] = true;
                    $changed = true;
                }
            }
            if (!$changed) {
                return;
            }
        }
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function calleeIsNoThrow(Context $context, Call $toCall, array $callArgs = []): bool
    {
        if (self::pureBuiltinArgsAreNoThrow($toCall, $callArgs)) {
            return true;
        }
        if (!($toCall instanceof Native || $toCall instanceof Vararg)) {
            return false;
        }
        $name = strtolower((string) $toCall->name);
        if ('' === $name) {
            return false;
        }
        if (!empty($context->noThrowUserFunctions[$name])) {
            return true;
        }
        // `{main}` lowers call sites before runQueue; reverse declaration order
        // (caller before callee) needs a lazy fixpoint so mid/top upgrade after
        // leaf is proven (#36386 call chains).
        if ([] !== $context->noThrowAnalyzeBlocks) {
            self::refineFixpoint($context);
        }

        return !empty($context->noThrowUserFunctions[$name]);
    }

    /**
     * True when {@code $toCall} is a recorded single-param identity user function.
     */
    public static function calleeIsTrivialIdentity(Context $context, Call $toCall): bool
    {
        if (!($toCall instanceof Native)) {
            return false;
        }
        $name = strtolower((string) $toCall->name);
        if ('' === $name) {
            return false;
        }
        if (!empty($context->trivialIdentityUserFunctions[$name])) {
            return true;
        }
        if ([] !== $context->noThrowAnalyzeBlocks) {
            self::refineFixpoint($context);
        }

        return !empty($context->trivialIdentityUserFunctions[$name]);
    }

    /**
     * Replace {@code id($x)} with the compiled argument when the callee is a
     * single-param identity. Returns null when the call must be emitted.
     *
     * @param array<int, Variable> $callArgs
     */
    public static function tryEmitTrivialIdentity(
        Context $context,
        Call $toCall,
        array $callArgs
    ): ?\PHPLLVM\Value {
        if (!self::calleeIsTrivialIdentity($context, $toCall)) {
            return null;
        }
        if (!($toCall instanceof Native)) {
            return null;
        }
        // One formal only — methods (`$this` + args) and multi-arg stay as calls.
        if (1 !== \count($toCall->argTypes)) {
            return null;
        }
        if ([] !== $toCall->paramByRefByArg || null !== $toCall->variadicArgIndex) {
            return null;
        }
        if (isset($callArgs[0]) && $callArgs[0] instanceof Variable) {
            $arg = $callArgs[0];
        } elseif (isset($toCall->defaultArgs[0])) {
            $arg = Native::materializeDefaultArg($context, $toCall->defaultArgs[0]);
        } else {
            return null;
        }

        return $toCall->compileArgForCall($context, $arg, 0);
    }

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
