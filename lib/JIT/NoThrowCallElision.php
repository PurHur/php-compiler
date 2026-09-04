<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Func\Internal as CoreFuncInternal;
use PHPCompiler\OpCode;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Call\Vararg;

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
 * numeric scalars (php-src {@code ext/standard/string.c}
 * {@code PHP_FUNCTION(strlen)} / {@code ord} / {@code chr} / {@code ucwords} /
 * {@code substr} / {@code strcmp} / {@code strpos} / {@code str_contains};
 * {@code ext/standard/url.c} {@code urlencode}; {@code ext/standard/crc32.c} /
 * {@code md5.c} / {@code base64.c}; {@code ext/standard/type.c}
 * {@code is_*}; {@code ext/standard/math.c} {@code PHP_FUNCTION(sqrt)} /
 * {@code pow} etc.; throwing {@code __toString} needs an object/value box).
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
 */
final class NoThrowCallElision
{
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

        return false;
    }

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
     * php-src {@code ext/standard/string.c} / {@code number_format.c} — formats a
     * numeric into a string. Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureNumberFormatBuiltin(string $nameLc): bool
    {
        return 'number_format' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/type.c} / {@code basic_functions.c} scalar casts
     * that only read typed scalars (no {@code __toString} / array-to-string
     * warning). Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureScalarCastBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'intval':
            case 'floatval':
            case 'doubleval':
            case 'boolval':
            case 'strval':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/math.c} base / radix converts — int→string
     * ({@code decbin}/{@code dechex}/{@code decoct}) or string→number
     * ({@code bindec}/{@code hexdec}/{@code octdec}) or {@code base_convert}.
     * Soft-null / object {@code __toString} stay out. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureBaseConvertBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'decbin':
            case 'dechex':
            case 'decoct':
            case 'bindec':
            case 'hexdec':
            case 'octdec':
            case 'base_convert':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/basic_functions.c} {@code ip2long}/
     * {@code long2ip}/{@code inet_pton}/{@code inet_ntop} — typed string or
     * long; soft-null stays out. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPureInetBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'ip2long':
            case 'long2ip':
            case 'inet_pton':
            case 'inet_ntop':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/array.c} {@code min}/{@code max} and
     * {@code math.c} {@code fmin}/{@code fmax} on typed numeric scalars —
     * no user handlers. Single-array {@code min}/{@code max} stays out.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureMinMaxBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'min':
            case 'max':
            case 'fmin':
            case 'fmax':
                return true;
            default:
                return false;
        }
    }

    /**
     * php-src {@code ext/standard/datetime.c} {@code checkdate} — three longs;
     * invalid calendar dates return false (no throw). Soft-null deprecates.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureCheckdateBuiltin(string $nameLc): bool
    {
        return 'checkdate' === $nameLc;
    }

    /**
     * php-src {@code ext/hash/hash.c} {@code hash_equals} — two strings;
     * TypeError on non-string / soft-null stays out. Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureHashEqualsBuiltin(string $nameLc): bool
    {
        return 'hash_equals' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/basic_functions.c} / {@code file.c}
     * {@code pathinfo} — Z_PARAM_STR path + optional Z_PARAM_LONG flags.
     * Soft-null path/flags deprecate. Public for {@see DiscardedPureCallElision}
     * (#36386).
     */
    public static function isPurePathinfoBuiltin(string $nameLc): bool
    {
        return 'pathinfo' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/url.c} {@code parse_url} — Z_PARAM_STR url +
     * optional Z_PARAM_LONG component. Soft-null url/component deprecate.
     * Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureParseUrlBuiltin(string $nameLc): bool
    {
        return 'parse_url' === $nameLc;
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code function_exists} —
     * Z_PARAM_STR name; function table lookup only (no autoload). Soft-null
     * deprecates. Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureFunctionExistsBuiltin(string $nameLc): bool
    {
        return 'function_exists' === $nameLc;
    }

    /**
     * php-src {@code ext/standard/info.c} {@code extension_loaded} — Z_PARAM_STR
     * extension; registered-module table lookup. Soft-null deprecates. Public
     * for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureExtensionLoadedBuiltin(string $nameLc): bool
    {
        return 'extension_loaded' === $nameLc;
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code method_exists} —
     * object|string + Z_PARAM_STR method. Only the typed-object receiver is
     * proven no-throw / discardable (string class names autoload). Public for
     * {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPureMethodExistsBuiltin(string $nameLc): bool
    {
        return 'method_exists' === $nameLc;
    }

    /**
     * php-src {@code Zend/zend_builtin_functions.c} {@code property_exists} —
     * object|string + Z_PARAM_STR property. Typed-object receiver only (string
     * class names autoload). Public for {@see DiscardedPureCallElision} (#36386).
     */
    public static function isPurePropertyExistsBuiltin(string $nameLc): bool
    {
        return 'property_exists' === $nameLc;
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
     * @param array<int, Variable> $callArgs
     */
    public static function versionCompareArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0], $callArgs[1])
            || !$callArgs[0] instanceof Variable
            || !$callArgs[1] instanceof Variable
            || !self::stringParamBuiltinArgCannotThrow($callArgs[0])
            || !self::stringParamBuiltinArgCannotThrow($callArgs[1])
        ) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return true;
        }
        if (!$callArgs[2] instanceof Variable || isset($callArgs[3])) {
            return false;
        }
        // Null operator → two-arg form (no ValueError).
        if ($callArgs[2]->isNullConstant || Variable::TYPE_NULL === $callArgs[2]->type) {
            return true;
        }
        $op = JitStringArg::compileTimeLiteral($callArgs[2]);
        if (null === $op) {
            // Unknown typed string may be an invalid operator (ValueError).
            return false;
        }

        return self::isValidVersionCompareOperatorLiteral($op);
    }

    /**
     * php-src {@code versioning.c} operator set (lt/le/gt/ge/eq/ne + symbols).
     */
    public static function isValidVersionCompareOperatorLiteral(string $operator): bool
    {
        switch ($operator) {
            case '<':
            case 'lt':
            case '<=':
            case 'le':
            case '>':
            case 'gt':
            case '>=':
            case 'ge':
            case '==':
            case '=':
            case 'eq':
            case '!=':
            case '<>':
            case 'ne':
                return true;
            default:
                return false;
        }
    }

    /** Compile-time long in [2, 36] — {@code base_convert} radix (math.c). */
    public static function compileTimeRadixBaseInRange(Variable $arg): bool
    {
        return null !== $arg->compileTimeLong
            && $arg->compileTimeLong >= 2
            && $arg->compileTimeLong <= 36;
    }

    /**
     * @param array<int, Variable> $callArgs
     */
    public static function scalarCastArgsCannotThrow(string $nameLc, array $callArgs): bool
    {
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        switch ($nameLc) {
            case 'intval':
                // value [, long base] — soft-null base deprecates (stay live).
                if (!self::scalarCastValueArgCannotThrow($callArgs[0], true)) {
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
            case 'floatval':
            case 'doubleval':
            case 'boolval':
                // Single scalar (boolval also accepts typed arrays — no user handler).
                if (isset($callArgs[1])) {
                    return false;
                }
                if ('boolval' === $nameLc && self::typedArrayArgCannotThrow($callArgs[0])) {
                    return true;
                }

                return self::scalarCastValueArgCannotThrow($callArgs[0], true);
            case 'strval':
                // Objects invoke __toString; arrays warn — typed scalars / null only.
                return !isset($callArgs[1])
                    && self::scalarCastValueArgCannotThrow($callArgs[0], true);
            default:
                return false;
        }
    }

    /**
     * Typed string / numeric / bool / null — no object / value-box / hashtable.
     */
    private static function scalarCastValueArgCannotThrow(Variable $arg, bool $allowNull): bool
    {
        if ($allowNull && ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type)) {
            return true;
        }
        if (self::stringParamBuiltinArgCannotThrow($arg)) {
            return true;
        }

        return self::numericParamBuiltinArgCannotThrow($arg);
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
     * {@code number_format} — numeric num [, long decimals [, string|null sep…]].
     *
     * @param array<int, Variable> $callArgs
     */
    public static function numberFormatArgsCannotThrow(array $callArgs): bool
    {
        if (
            !isset($callArgs[0])
            || !$callArgs[0] instanceof Variable
            || !self::numericParamBuiltinArgCannotThrow($callArgs[0])
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
        for ($i = 2; $i <= 3; ++$i) {
            if (!isset($callArgs[$i])) {
                return true;
            }
            if (
                !$callArgs[$i] instanceof Variable
                || !(
                    self::stringParamBuiltinArgCannotThrow($callArgs[$i])
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

    /**
     * php-src {@code ext/standard/math.c} builtins that only coerce a numeric
     * scalar and never invoke user handlers (no {@code __toString} on object /
     * value-box paths we already exclude via {@see numericParamBuiltinArgCannotThrow}).
     *
     * Public for {@see DiscardedPureCallElision} — discarded statements of these
     * builtins are side-effect-free when args are already numeric (#36386).
     */
    public static function isPureMathBuiltin(string $nameLc): bool
    {
        switch ($nameLc) {
            case 'sqrt':
            case 'abs':
            case 'floor':
            case 'ceil':
            case 'round':
            case 'sin':
            case 'cos':
            case 'tan':
            case 'asin':
            case 'acos':
            case 'atan':
            case 'sinh':
            case 'cosh':
            case 'tanh':
            case 'asinh':
            case 'acosh':
            case 'atanh':
            case 'exp':
            case 'expm1':
            case 'log':
            case 'log10':
            case 'log1p':
            case 'hypot':
            case 'fmod':
            case 'atan2':
            case 'deg2rad':
            case 'rad2deg':
            // math.c pow / fpow / fdiv — no user handlers; domain errors are
            // NAN/INF (fdiv ÷0 → INF). intdiv stays out (DivisionByZeroError).
            case 'pow':
            case 'fpow':
            case 'fdiv':
            // math.c pi() — zero-arg constant (M_PI); no user handlers.
            case 'pi':
                return true;
            default:
                return false;
        }
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

    /**
     * True when the body cannot throw and every FUNCCALL target is self or an
     * already-proven no-throw user function.
     */
    private static function bodyIsNoThrowCalleeGraph(
        Block $entry,
        string $selfLc,
        Context $context
    ): bool {
        $seen = [];
        $stack = [$entry];
        while ([] !== $stack) {
            /** @var Block $block */
            $block = array_pop($stack);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            foreach ($block->opCodes as $op) {
                $type = $op->type;
                if (OpCode::TYPE_FUNCDEF === $type || OpCode::TYPE_CLOSURE === $type) {
                    // Nested declarations are other functions — do not attribute their
                    // bodies to this one, and do not walk into them.
                    continue;
                }
                if (OpCode::TYPE_THROW === $type
                    || OpCode::TYPE_NEW === $type
                    || OpCode::TYPE_INCLUDE === $type
                    || OpCode::TYPE_FROM_CALLABLE === $type
                ) {
                    return false;
                }
                if (OpCode::TYPE_FUNCCALL_INIT === $type) {
                    if (!empty($op->funcCallDynamic)) {
                        return false;
                    }
                    $nameOp = $block->getOperand($op->arg1);
                    if (!$nameOp instanceof Operand\Literal) {
                        return false;
                    }
                    $calleeLc = strtolower((string) $nameOp->value);
                    if (!self::isAllowedNoThrowCallee($context, $selfLc, $calleeLc)) {
                        return false;
                    }
                }
                if (OpCode::TYPE_METHODCALL_INIT === $type) {
                    // Same-class `$this->leaf()` chains: allow when the target method
                    // is already proven no-throw (fixpoint upgrades mid after leaf).
                    // Cross-class bare-name matches are rejected — two classes can
                    // share a method name with different throw behaviour (#36386).
                    $methodLc = self::literalMethodNameLc($block, $op->arg2);
                    if (null === $methodLc
                        || !self::isAllowedNoThrowMethodCallee($context, $selfLc, $methodLc)
                    ) {
                        return false;
                    }
                }
                if (OpCode::TYPE_STATICCALL_INIT === $type) {
                    // Same-class `self::leaf()` / `A::leaf()` chains — same fixpoint
                    // as METHODCALL. `parent::` stays conservative (needs inheritance).
                    $classLc = self::literalClassNameLc($block, $op->arg1);
                    $methodLc = self::literalMethodNameLc($block, $op->arg2);
                    if (null === $classLc
                        || null === $methodLc
                        || !self::isAllowedNoThrowStaticCallee($context, $selfLc, $classLc, $methodLc)
                    ) {
                        return false;
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $child) {
                    if ($child instanceof Block) {
                        $stack[] = $child;
                    }
                }
            }
            foreach ($block->blocks as $child) {
                if ($child instanceof Block) {
                    $stack[] = $child;
                }
            }
        }

        return true;
    }

    private static function isAllowedNoThrowCallee(
        Context $context,
        string $selfLc,
        string $calleeLc
    ): bool {
        // Self-recursion uses the bare method name in CFG; scoped
        // `Class::method` keys must still match (#36386 leaf methods).
        if ($calleeLc === $selfLc || $calleeLc === self::bareName($selfLc)) {
            return true;
        }
        if (!empty($context->noThrowUserFunctions[$calleeLc])) {
            return true;
        }
        // Scoped key vs bare CFG name (Class::leaf ↔ leaf).
        $bare = self::bareName($calleeLc);
        if ($bare !== $calleeLc && !empty($context->noThrowUserFunctions[$bare])) {
            return true;
        }
        foreach ($context->noThrowUserFunctions as $knownLc => $ok) {
            if (!$ok) {
                continue;
            }
            if (self::bareName($knownLc) === $calleeLc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Instance method callees are keyed {@code class::method}. Prefer the
     * caller's class scope so {@code B::leaf} throwing does not unlock
     * {@code A::mid}'s {@code $this->leaf()} when only {@code A::leaf} is safe.
     */
    private static function isAllowedNoThrowMethodCallee(
        Context $context,
        string $selfLc,
        string $methodLc
    ): bool {
        if ($methodLc === self::bareName($selfLc)) {
            return true;
        }
        $class = self::classPrefix($selfLc);
        if ('' !== $class) {
            $scoped = $class.'::'.$methodLc;
            if (!empty($context->noThrowUserFunctions[$scoped])) {
                return true;
            }
        }
        if (!empty($context->noThrowUserFunctions[$methodLc])) {
            return true;
        }

        return false;
    }

    /**
     * Resolve {@code self::}/{@code static::}/{@code Class::} static callees.
     * Prefer the explicit class::method key so {@code B::leaf} throwing does not
     * unlock {@code A::mid}'s {@code self::leaf()} when only {@code A::leaf} is safe.
     */
    private static function isAllowedNoThrowStaticCallee(
        Context $context,
        string $selfLc,
        string $classLitLc,
        string $methodLc
    ): bool {
        if ('parent' === $classLitLc) {
            return false;
        }
        $callerClass = self::classPrefix($selfLc);
        $targetClass = $classLitLc;
        if ('self' === $targetClass || 'static' === $targetClass) {
            if ('' === $callerClass) {
                return false;
            }
            $targetClass = $callerClass;
        }
        $targetClass = ltrim($targetClass, '\\');
        if ('' === $targetClass || '' === $methodLc) {
            return false;
        }
        // Recursing into the same static method (rare) — bare or scoped.
        if ($methodLc === self::bareName($selfLc)
            && ('' === $callerClass || $targetClass === $callerClass)
        ) {
            return true;
        }
        $scoped = $targetClass.'::'.$methodLc;
        if (!empty($context->noThrowUserFunctions[$scoped])) {
            return true;
        }
        if (!empty($context->noThrowUserFunctions[$methodLc])
            && ('' === $callerClass || $targetClass === $callerClass)
        ) {
            return true;
        }

        return false;
    }

    private static function literalClassNameLc(Block $block, ?int $classSlot): ?string
    {
        return self::literalOperandStringLc($block, $classSlot);
    }

    private static function literalMethodNameLc(Block $block, ?int $nameSlot): ?string
    {
        return self::literalOperandStringLc($block, $nameSlot);
    }

    private static function literalOperandStringLc(Block $block, ?int $slot): ?string
    {
        if (null === $slot) {
            return null;
        }
        $nameOp = $block->getOperand($slot);
        if (!$nameOp instanceof Operand\Literal && isset($block->constants[$slot])) {
            $nameOp = new Operand\Literal($block->constants[$slot]->toString());
        }
        if (!$nameOp instanceof Operand\Literal) {
            return null;
        }
        $raw = is_string($nameOp->value) ? $nameOp->value : (string) $nameOp->value;
        if ('' === $raw) {
            return null;
        }

        return strtolower($raw);
    }

    private static function bareName(string $scopedLc): string
    {
        $pos = strrpos($scopedLc, '::');
        if (false === $pos) {
            return $scopedLc;
        }

        return substr($scopedLc, $pos + 2);
    }

    private static function classPrefix(string $scopedLc): string
    {
        $pos = strrpos($scopedLc, '::');
        if (false === $pos) {
            return '';
        }

        return substr($scopedLc, 0, $pos);
    }
}
