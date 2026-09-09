<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Call\Vararg;

require_once __DIR__.'/NoThrowCallElisionRuntimeInfoPredicates.php';
require_once __DIR__.'/NoThrowCallElisionExistsConvertAndIntrospectOps.php';
require_once __DIR__.'/NoThrowCallElisionTypeCtypeHtmlOps.php';
require_once __DIR__.'/NoThrowCallElisionStringSlicePadReplaceOps.php';
require_once __DIR__.'/NoThrowCallElisionMathAndFormatOps.php';
require_once __DIR__.'/NoThrowCallElisionCalleeGraph.php';
require_once __DIR__.'/NoThrowCallElisionPureBuiltinArgOps.php';

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
 * {@see NoThrowCallElisionTypeCtypeHtmlOps} (#36387).
 * Math / number_format / scalar-cast / base-inet-minmax / path-url
 * proofs live in {@see NoThrowCallElisionMathAndFormatOps} (#36387).
 * Callee-graph proofs live in {@see NoThrowCallElisionCalleeGraph} (#36387).
 * Pure-builtin arg dispatch + shared scalar/array arg helpers live in
 * {@see NoThrowCallElisionPureBuiltinArgOps} (#36387).
 */
final class NoThrowCallElision
{
    use NoThrowCallElisionRuntimeInfoPredicates;

    use NoThrowCallElisionExistsConvertAndIntrospectOps;

    use NoThrowCallElisionTypeCtypeHtmlOps;
    use NoThrowCallElisionStringSlicePadReplaceOps;
    use NoThrowCallElisionMathAndFormatOps;
    use NoThrowCallElisionCalleeGraph;
    use NoThrowCallElisionPureBuiltinArgOps;

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

}
