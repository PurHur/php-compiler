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
 * long, pure type predicates ({@code is_int} / {@code is_string} / …), and
 * pure math ({@code sqrt} / {@code abs} / {@code floor} / …) on native numeric
 * scalars (php-src {@code ext/standard/string.c} {@code PHP_FUNCTION(strlen)} /
 * {@code ord} / {@code chr}; {@code ext/standard/type.c} {@code is_*};
 * {@code ext/standard/math.c} {@code PHP_FUNCTION(sqrt)} etc.; throwing
 * {@code __toString} needs an object/value box). Discarded {@code ord}/{@code chr}
 * with the same arg proofs are dropped entirely by {@see DiscardedPureCallElision}.
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
        } elseif (isset($toCall->defaultArgs[0]) && $toCall->defaultArgs[0] instanceof Variable) {
            $arg = $toCall->defaultArgs[0];
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
            // is_int / is_string / gettype / … never invoke user handlers
            // (php-src type.c / basic_functions.c). Exclude is_callable / is_a
            // (autoload / __invoke).
            return true;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if ('strlen' === $name || 'ord' === $name || self::isPureStringTransformBuiltin($name)) {
            // Z_PARAM_STR family — __toString only on object / value-box.
            // trim/ltrim/rtrim optional $characters must also be throw-free.
            foreach ($callArgs as $arg) {
                if (!$arg instanceof Variable || !self::stringParamBuiltinArgCannotThrow($arg)) {
                    return false;
                }
            }

            return true;
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
            case 'strrev':
            case 'trim':
            case 'ltrim':
            case 'rtrim':
                return true;
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
