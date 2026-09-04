<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

use PHPCfg\Op;
use PHPCfg\Operand\Literal;
use SplObjectStorage;

/**
 * compileBlock / compileOps, statement ticks, and string-dim CFG splits (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers declare(ticks) emission before statements, echo-arg prelude detection,
 * class/function/const hoist + nullsafe/coalesce op dispatch in compileOps, and
 * AOT CFG splits before string-keyed ArrayDimFetch (#764 / #23354).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait CompileOpsTicksAndCfgSplit
{

    protected function compileBlock(Block $block) {
        if (null !== $block->orig && $this->isErrorSuppressEndBlock($block->orig)) {
            $block->addOpCode(new OpCode(OpCode::TYPE_END_SILENCE));
        }
        $this->compileOps($block->orig->children, $block);
        // Do not auto-LEAVE at CFG block edges: file-level declare(ticks=N) and braced
        // bodies that span loops/jumps must keep the interval across successor blocks.
        // Braced scopes emit LeaveTickInterval explicitly from php-cfg (#22840, #23486).
    }

    private function emitTicksBeforeStatementIfNeeded(Op $op, Block $block, array $ops, int $index): void
    {
        if ($this->activeTickInterval <= 0) {
            return;
        }
        if ($op instanceof Op\Terminal\SetTickInterval || $op instanceof Op\Terminal\LeaveTickInterval) {
            return;
        }
        // for ($i=0; $i<n; $i++) init/increment exprs are not Zend statement boundaries (#23486, #25621).
        if ($op->hasAttribute('for_loop_increment') && $op->getAttribute('for_loop_increment')) {
            return;
        }
        if ($op->hasAttribute('for_loop_init') && $op->getAttribute('for_loop_init')) {
            return;
        }
        if (
            $op instanceof Op\Stmt\Function_
            || $op instanceof Op\Stmt\Class_
            || $op instanceof Op\Stmt\Interface_
            || $op instanceof Op\Stmt\Trait_
            || $op instanceof Op\Stmt\Enum_
            || $op instanceof Op\Stmt\Jump
            || $op instanceof Op\Stmt\JumpIf
            || $op instanceof Op\Terminal\Const_
            || $op instanceof Op\Terminal\Return_
        ) {
            return;
        }
        // php-cfg lowers `$x += 1` to BinaryOp + Assign — only the Assign is tickable (#22840).
        if ($op instanceof Op\Expr\BinaryOp) {
            return;
        }
        // echo "a$b" → ConcatList + Echo. Zend ticks at the statement start (before
        // interpolation). Tick before ConcatList; skip the following Echo (#23486).
        if ($op instanceof Op\Expr\ConcatList) {
            $next = $ops[$index + 1] ?? null;
            if ($next instanceof Op\Terminal && 'Terminal_Echo' === $next->getType()) {
                $block->addOpCode(new OpCode(OpCode::TYPE_TICKS));
            }

            return;
        }
        if ($op instanceof Op\Expr\Closure) {
            return;
        }
        // php-src places ZEND_TICKS before each ECHO opcode. `echo $a, $b` and
        // `echo "a"; echo "b"` both lower to consecutive Terminal_Echo — tick each
        // fragment. Skip only the Echo that follows ConcatList (already ticked) (#30010).
        if ($op instanceof Op\Terminal && 'Terminal_Echo' === $op->getType()) {
            $prev = $ops[$index - 1] ?? null;
            if ($prev instanceof Op\Expr\ConcatList) {
                return;
            }
        }
        // Arg evaluation feeding a following Echo (FuncCall/BinaryOp/…) is not a
        // separate Zend statement boundary — ZEND_TICKS sits on the ECHO (#30010).
        if ($this->isEchoArgEvaluationPrelude($op, $ops, $index)) {
            return;
        }
        $block->addOpCode(new OpCode(OpCode::TYPE_TICKS));
    }

    /**
     * True when $op only evaluates an argument of a following Terminal_Echo.
     *
     * Mirrors php-src: ZEND_TICKS is emitted with each ECHO, not with the arg-setup
     * ops php-cfg materializes ahead of it (`echo strtoupper("a"), "b"` / `echo foo(bar())`).
     *
     * Call-arg site clones often break Temporary identity (#8560), so this uses a
     * same-startLine window: producers sharing the echo statement's line are preludes,
     * except `foo(); echo …` where the call result is unused by the following Echo.
     *
     * @param Op[] $ops
     */
    private function isEchoArgEvaluationPrelude(Op $op, array $ops, int $index): bool
    {
        if (!($op instanceof Op\Expr) || !property_exists($op, 'result') || null === $op->result) {
            return false;
        }
        // Statement-level assign (`$a = 1; echo $a`) must tick; only `echo ($a = …)`
        // feeds the Echo expr directly.
        if (
            $op instanceof Op\Expr\Assign
            || $op instanceof Op\Expr\AssignRef
            || $op instanceof Op\Expr\AssignOp
        ) {
            $next = $ops[$index + 1] ?? null;
            if (!($next instanceof Op\Terminal) || 'Terminal_Echo' !== $next->getType()) {
                return false;
            }

            return $this->operandsChainEqual($next->expr, $op->result)
                || $this->operandsReferToSameVariable($next->expr, $op->result);
        }
        if (!$this->isInlineExprCallArgProducer($op)) {
            return false;
        }

        $line = $op->hasAttribute('startLine')
            ? (int) $op->getAttribute('startLine')
            : $op->getLine();
        if ($line <= 0) {
            return false;
        }

        $produced = $op->result;
        $sawEcho = false;
        $resultFeedsEcho = false;
        $immediate = $ops[$index + 1] ?? null;
        $n = \count($ops);
        for ($j = $index + 1; $j < $n; ++$j) {
            $next = $ops[$j];
            $nextLine = $next->hasAttribute('startLine')
                ? (int) $next->getAttribute('startLine')
                : $next->getLine();
            if ($nextLine !== $line) {
                break;
            }
            if ($next instanceof Op\Terminal && 'Terminal_Echo' === $next->getType()) {
                $sawEcho = true;
                if (
                    $this->operandsChainEqual($next->expr, $produced)
                    || $this->operandsReferToSameVariable($next->expr, $produced)
                ) {
                    $resultFeedsEcho = true;
                }
                continue;
            }
            if (
                !($next instanceof Op\Expr)
                || !property_exists($next, 'result')
                || null === $next->result
                || !$this->isInlineExprCallArgProducer($next)
            ) {
                break;
            }
        }
        if (!$sawEcho) {
            return false;
        }
        if ($resultFeedsEcho) {
            return true;
        }
        // Nested call arg: `echo foo(bar())` — bar sits before foo on the echo line.
        if (
            $immediate instanceof Op\Expr
            && $this->isInlineExprCallArgProducer($immediate)
            && !($immediate instanceof Op\Expr\Assign)
            && !($immediate instanceof Op\Expr\AssignRef)
            && !($immediate instanceof Op\Expr\AssignOp)
        ) {
            return true;
        }
        // `foo(); echo "a"` on one line — call result unused by Echo; still tickable.
        if ($immediate instanceof Op\Terminal && 'Terminal_Echo' === $immediate->getType()) {
            return false;
        }

        return true;
    }

    protected function compileOps(array $ops, Block $block): void {
        // Enum cases before global const / hoisted class bodies so E::A folds in
        // class const initializers when enum appears later in source (#15737, #5738).
        $this->prescanCompileTimeEnumCases($ops);
        // Register file-level `const` / literal define() before class bodies and
        // FUNCDEF defaults so zend_compile_default_value can fold ConstFetch (#6542).
        $this->prescanCompileTimeGlobalConsts($ops, $block);
        $this->rejectListDestructDefaultValueSlotsInOps($ops);

        // Hoist class-like definitions before functions so JIT/AOT see member
        // constants when compiling FUNCDEF bodies (issue #2215, MiniWebApp Router::CONST).
        // Interfaces before classes so same-file `class C implements I` / later `interface I`
        // resolves at DECLARE_CLASS like Zend early-binding (#25624).
        // Enums stay in source order so enum_exists() before declaration matches Zend (#5013).
        // Serializable / forbidden-implements / trait-use stay in source order for DECLARE
        // side effects (#18781, #25109, #25912). Subclasses of those classes stay in source
        // order too — hoisting them ahead leaves deferred parent inheritance pending across
        // preceding runtime opcodes, which finalize as Class "Parent" not found (#29552, #29566).
        $sourceOrderClassLcs = $this->sourceOrderClassRegistrationLcs($ops);
        foreach ($ops as $child) {
            if ($child instanceof Op\Stmt\Interface_) {
                $block->addOpCode($this->compileInterface($child, $block));
            }
        }
        foreach ($ops as $child) {
            if ($child instanceof Op\Stmt\Trait_) {
                $block->addOpCode($this->compileTrait($child, $block));
            }
        }
        foreach ($ops as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            if ($this->classIsSourceOrderRegistration($child, $sourceOrderClassLcs)) {
                continue;
            }
            $block->addOpCode($this->compileClassLike($child, $block));
        }
        foreach ($ops as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                    // Already emitted at {main} entry for Zend early-binding (#24807).
                    if ($this->earlyBoundFunctionOps->contains($child)) {
                        break;
                    }
                    $block->addOpCode($this->compileFunction($child, $block));
                    break;
                case Op\Terminal\Const_::class:
                    $block->addOpCode($this->compileGlobalConst($child, $block));
                    break;
            }
        }

        // php-cfg may linearize nullsafe-call arguments into eager temporaries:
        //
        //   $t = sideEffect();
        //   $c?->f($t);
        //
        // For PHP semantics, those argument temporaries must only be evaluated on the
        // non-null receiver branch (Zend `?->` short-circuit). We detect a small
        // producer slice that is used exclusively to feed a nullsafe method-call
        // argument and compile that slice into the nullsafe fetch block instead (#4394).
        $deferredNullsafePreludeOps = new SplObjectStorage();
        $deferredOpIndexes = [];
        $opCount = count($ops);
        for ($i = 0; $i < $opCount; ++$i) {
            $child = $ops[$i];
            if (!$child instanceof Op\Expr\NullsafeMethodCall) {
                continue;
            }

            $needed = [];
            foreach ($child->args as $arg) {
                if ($arg instanceof \PHPCfg\Operand\Temporary) {
                    $needed[spl_object_id($arg)] = $arg;
                }
            }
            if (empty($needed)) {
                continue;
            }

            $slice = [];
            for ($j = $i - 1; $j >= 0 && !empty($needed); --$j) {
                $candidate = $ops[$j] ?? null;
                if (!$candidate instanceof Op\Expr) {
                    break;
                }
                if ($candidate instanceof Op\Expr\Assign) {
                    break;
                }
                if (!property_exists($candidate, 'result') || !$candidate->result instanceof \PHPCfg\Operand\Temporary) {
                    break;
                }
                $resultVar = $candidate->result;
                // php-cfg parseArg() clones producer temps for call sites (#8560); match the
                // clone via shared ops, not only identical operand objects (#22660).
                $matchedArgId = null;
                if (isset($needed[spl_object_id($resultVar)])) {
                    $matchedArgId = spl_object_id($resultVar);
                } else {
                    foreach ($needed as $argId => $argTemp) {
                        if ($this->nullsafeCallArgTempFedByProducer($argTemp, $candidate)) {
                            $matchedArgId = $argId;
                            break;
                        }
                    }
                }
                if (null === $matchedArgId) {
                    continue;
                }

                $slice[] = $candidate;
                unset($needed[$matchedArgId]);
                $deferredOpIndexes[$j] = true;

                foreach ($this->nullsafePreludeOperandVars($candidate) as $dep) {
                    if ($dep instanceof \PHPCfg\Operand\Temporary) {
                        $needed[spl_object_id($dep)] = $dep;
                    }
                }
            }

            if (!empty($slice)) {
                $deferredNullsafePreludeOps[$child] = array_reverse($slice);
            } elseif ($i > 0) {
                // php-cfg may use a distinct arg temporary vs the immediately preceding
                // inline producer (IIFE FuncCall) — defer that prelude slice (#17186, #4394).
                $head = $ops[$i - 1] ?? null;
                if (
                    $head instanceof Op\Expr
                    && !$head instanceof Op\Expr\Assign
                    && property_exists($head, 'result')
                    && $this->isNullsafeMethodCallArgPreludeProducer($head)
                ) {
                    $adjacentSlice = [$head];
                    $deferredOpIndexes[$i - 1] = true;
                    $pendingDeps = [];
                    foreach ($this->nullsafePreludeOperandVars($head) as $dep) {
                        if ($dep instanceof \PHPCfg\Operand\Temporary) {
                            $pendingDeps[spl_object_id($dep)] = $dep;
                        }
                    }
                    for ($j = $i - 2; $j >= 0 && [] !== $pendingDeps; --$j) {
                        $candidate = $ops[$j] ?? null;
                        if (
                            !$candidate instanceof Op\Expr
                            || $candidate instanceof Op\Expr\Assign
                            || !property_exists($candidate, 'result')
                            || !$candidate->result instanceof \PHPCfg\Operand\Temporary
                        ) {
                            break;
                        }
                        $resultVar = $candidate->result;
                        $matchedDep = null;
                        foreach ($pendingDeps as $depId => $dep) {
                            if ($resultVar === $dep || $this->operandsReferToSameVariable($resultVar, $dep)) {
                                $matchedDep = $depId;
                                break;
                            }
                        }
                        if (null === $matchedDep) {
                            break;
                        }
                        unset($pendingDeps[$matchedDep]);
                        array_unshift($adjacentSlice, $candidate);
                        $deferredOpIndexes[$j] = true;
                        foreach ($this->nullsafePreludeOperandVars($candidate) as $dep) {
                            if ($dep instanceof \PHPCfg\Operand\Temporary) {
                                $pendingDeps[spl_object_id($dep)] = $dep;
                            }
                        }
                    }
                    if ([] !== $adjacentSlice) {
                        $deferredNullsafePreludeOps[$child] = $adjacentSlice;
                    }
                }
            }
        }
        for ($i = 0; $i < $opCount; ++$i) {
            if (isset($deferredOpIndexes[$i])) {
                continue;
            }
            $child = $ops[$i];
            $prevCompileErrorContextOp = $this->compileErrorContextOp;
            $this->compileErrorContextOp = $child;
            if ($child instanceof Op\Expr\ArrayDimFetch) {
                $this->rejectArrayEmptyOffsetRead($child, $block);
            }
            $this->debugWriteLastPhase('Compiler::compileOps op', $block, $child);
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                case Op\Terminal\Const_::class:
                case Op\Stmt\Interface_::class:
                case Op\Stmt\Trait_::class:
                    break;
                case Op\Stmt\Class_::class:
                    if ($this->classIsSourceOrderRegistration($child, $sourceOrderClassLcs)) {
                        $block->addOpCode($this->compileClassLike($child, $block));
                    }
                    break;
                case Op\Stmt\Enum_::class:
                    $block->addOpCode($this->compileEnum($child, $block));
                    break;
                default:
                    if ($child instanceof Op\Expr\Isset_ && count($child->vars) > 1) {
                        $block = $this->compileIssetMulti($child, $block);
                    } elseif (
                        $child instanceof Op\Expr\Isset_
                        && 1 === count($child->vars)
                        && [] !== ($nullsafeChain = $this->collectNullsafePropertyFetchChain($child->vars[0], $block))
                    ) {
                        $block = $this->compileIssetNullsafePropertyFetchChain($nullsafeChain, $child, $block);
                    } elseif (
                        $child instanceof Op\Expr\Empty_
                        && [] !== ($nullsafeChain = $this->collectNullsafePropertyFetchChainForEmpty($child, $block))
                    ) {
                        $block = $this->compileEmptyNullsafePropertyFetchChain($nullsafeChain, $child, $block);
                    } elseif ($child instanceof Op\Expr\BinaryOp\Coalesce) {
                        if ($this->isCoalesceChainInnerStmt($child, $ops, $i)) {
                            break;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $i)) {
                            break;
                        }
                        // php-cfg emits Coalesce before Throw when source is `throw … ?? …`; lower once inside compileThrowExpression (#15315).
                        if ($this->isCoalesceLoweredByFollowingThrow($ops, $i)) {
                            break;
                        }
                        $resultOverride = null;
                        if (
                            $i + 1 < $opCount
                            && $ops[$i + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$i + 1], $child)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$i + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        $block = null !== $resultOverride
                            ? $this->compileCoalesceForAssign($child, $block, $resultOverride)
                            : $this->compileCoalesce($child, $block);
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                    } elseif (
                        $child instanceof Op\Expr\NullsafePropertyFetch
                        && (
                            $this->shouldSkipNullsafePropertyFetchForIssetOrEmpty($child, $ops, $i, $block)
                            || $this->shouldSkipNullsafePropertyFetchForCoalesce($child, $ops, $i, $block)
                        )
                    ) {
                        // Lowered by compileIssetNullsafePropertyFetchChain / compileEmptyNullsafePropertyFetchChain (#4980)
                        // or compileCoalesce nullsafe chain eval (#13747).
                        break;
                    } elseif ($child instanceof Op\Expr\NullsafePropertyFetch) {
                        if ($this->isNullsafePropertyFetchInWriteContext($ops, $i)) {
                            $this->throwCompileError("Can't use nullsafe operator in write context");
                        }
                        // Zend: &$a?->x / &$a?->x->y — AssignRef RHS, not write-context LHS (#26638).
                        if ($this->isNullsafeOperandUsedAsAssignRefRhs($ops, $i + 1, $child->result)) {
                            $this->throwCompileError('Cannot take reference of a nullsafe chain');
                        }
                        $block = $this->compileNullsafePropertyFetch($child, $block);
                        $this->syncNullsafePropertyFetchResultToFollowingFuncCallArg($child, $block);
                    } elseif (
                        $child instanceof Op\Expr\NullsafeMethodCall
                        && $this->shouldSkipNullsafeMethodCallForCoalesce($child, $ops, $i, $block)
                    ) {
                        // Lowered inside compileCoalesce nullsafe method eval (#19591).
                        break;
                    } elseif ($child instanceof Op\Expr\NullsafeMethodCall) {
                        // Zend: &$obj?->m() — AssignRef of nullsafe method result (#26638).
                        if ($this->isNullsafeOperandUsedAsAssignRefRhs($ops, $i + 1, $child->result)) {
                            $this->throwCompileError('Cannot take reference of a nullsafe chain');
                        }
                        $block = $this->compileNullsafeMethodCall(
                            $child,
                            $block,
                            $deferredNullsafePreludeOps->contains($child) ? $deferredNullsafePreludeOps[$child] : []
                        );
                    } elseif ($this->isNullsafeChainArrayDimFetch($ops, $i)) {
                        /** @var Op\Expr\ArrayDimFetch $child */
                        $block = $this->compileNullsafeArrayDimFetch($child, $block);
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && ($ops[$i + 1] instanceof Op\Expr\FuncCall || $ops[$i + 1] instanceof Op\Expr\NsFuncCall)
                        && $this->isPropertyFetchOnlyCoalesceFuncCallArg($child, $ops[$i + 1], $block)
                    ) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $this->isPropertyFetchNullsafeReceiver($child, $ops, $i)
                    ) {
                        // Lowered inside compileNullsafePropertyFetch / coalesce chain eval (#16637).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && null !== ($coalesceMatch = $this->findCoalesceUsingPropertyFetchLeft($child, $ops, $i))
                    ) {
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        [$coalesce, $coalesceIndex] = $coalesceMatch;
                        // Nested ??= stmts may sit between hoisted fetch and outer ?? (#33760).
                        if ($coalesceIndex !== $i + 1) {
                            break;
                        }
                        $resultOverride = null;
                        if (
                            $coalesceIndex + 1 < $opCount
                            && $ops[$coalesceIndex + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$coalesceIndex + 1], $coalesce)
                            && $this->operandsChainEqual($ops[$coalesceIndex + 1]->var, $child->result)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$coalesceIndex + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $coalesceIndex)) {
                            $i = $coalesceIndex;
                            break;
                        }
                        $block = null !== $resultOverride
                            ? $this->compileCoalesceForAssign($coalesce, $block, $resultOverride)
                            : $this->compileCoalesce($coalesce, $block);
                        $i = $coalesceIndex;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\StaticPropertyFetch
                        && null !== ($coalesceMatch = $this->findCoalesceUsingStaticPropertyFetchLeft($child, $ops, $i))
                    ) {
                        // php-cfg hoists StaticPropertyFetch before ?? / ??=; skip the R-mode
                        // fetch so uninitialized typed statics stay BP_VAR_IS (#31146).
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        [$coalesce, $coalesceIndex] = $coalesceMatch;
                        if ($coalesceIndex !== $i + 1) {
                            break;
                        }
                        $resultOverride = null;
                        if (
                            $coalesceIndex + 1 < $opCount
                            && $ops[$coalesceIndex + 1] instanceof Op\Expr\Assign
                            && $this->isCoalesceAssignTail($ops[$coalesceIndex + 1], $coalesce)
                            && $this->operandsChainEqual($ops[$coalesceIndex + 1]->var, $child->result)
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$coalesceIndex + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $coalesceIndex)) {
                            $i = $coalesceIndex;
                            break;
                        }
                        $block = null !== $resultOverride
                            ? $this->compileCoalesceForAssign($coalesce, $block, $resultOverride)
                            : $this->compileCoalesce($coalesce, $block);
                        $i = $coalesceIndex;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $i + 1 < $opCount
                        && ($ops[$i + 1] instanceof Op\Expr\FuncCall || $ops[$i + 1] instanceof Op\Expr\NsFuncCall)
                        && $this->isArrayDimFetchOnlyCoalesceFuncCallArg($child, $ops[$i + 1], $block)
                    ) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $this->isArrayDimFetchSkippedForCoalesce($child, $ops, $i, $block)
                    ) {
                        // Nested `$a['x']['y'] ??…` / `??=` — intermediates lowered inside compileCoalesce (#28954).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && null !== ($coalesceMatch = $this->findCoalesceUsingArrayDimFetchLeft($child, $ops, $i))
                    ) {
                        /** @var Op\Expr\BinaryOp\Coalesce $coalesce */
                        [$coalesce, $coalesceIndex] = $coalesceMatch;
                        $resultOverride = null;
                        if (
                            $coalesceIndex + 1 < $opCount
                            && $ops[$coalesceIndex + 1] instanceof Op\Expr\Assign
                            && $this->isRedundantCoalesceTailAssign(
                                $ops[$coalesceIndex + 1],
                                $child,
                                $coalesce
                            )
                        ) {
                            /** @var Op\Expr\Assign $tailAssign */
                            $tailAssign = $ops[$coalesceIndex + 1];
                            $resultOverride = $tailAssign->var;
                        }
                        if ($this->isCoalesceLoweredByFollowingEchoConcat($ops, $coalesceIndex)) {
                            $i = $coalesceIndex;
                            break;
                        }
                        $block = $this->compileCoalesceForAssign($coalesce, $block, $resultOverride);
                        $i = $coalesceIndex;
                        if (null !== $resultOverride) {
                            ++$i;
                        }
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ArrayDimFetch
                        && $this->isArrayDimFetchSkippedForIssetEmptyOrUnset($child, $ops, $i, $block)
                    ) {
                        // Lowered by compileIsset / Empty_ / Unset — including nested dim chains (#99, #21991).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyIssetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileIsset via TYPE_ISSET(container, name) (#3298).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\StaticPropertyFetch
                        && $i + 1 < $opCount
                        && $this->isStaticPropertyFetchOnlyIssetVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileIsset via TYPE_ISSET(class, name) (#15112).
                        break;
                    } elseif ($child instanceof Op\Terminal\StaticVar) {
                        [$staticOps, $nextBlock] = $this->compileFunctionStaticVar($child, $block);
                        foreach ($staticOps as $staticOp) {
                            $block->addOpCode($staticOp);
                        }
                        $block = $nextBlock;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyUnsetVar($child, $ops[$i + 1])
                    ) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyAssignVar($child, $ops[$i + 1])
                    ) {
                        // Lowered by compileExpr Assign via TYPE_PROPERTY_FETCH + TYPE_ASSIGN (#6834).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchLoweredByFollowingArrayLiteralByRefElement(
                            $child,
                            $ops[$i + 1]
                        )
                    ) {
                        // Lowered by compileArrayLiteral PROPERTY_FETCH_WRITE + ASSIGN_REF (#6426, #17353).
                        break;
                    } elseif ($this->isLoweredByFollowingCoalesce($child, $ops, $i)) {
                        break;
                    } elseif ($this->isLoweredByFollowingThrow($child, $ops, $i)) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\Throw_
                        && $this->throwResultFeedsFollowingIsset($child, $ops, $i)
                    ) {
                        // php-cfg emits Throw_ before Isset_ for isset(throw …); do not run the throw (#29086).
                        $this->throwCompileError(self::ISSET_EXPRESSION_COMPILE_ERROR);
                    } elseif ($this->isUnreachableAfterThrow($child, $ops, $i)) {
                        break;
                    } elseif ($this->isUnreachableAfterNeverCall($child, $ops, $i)) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ClassConstFetch
                        && $this->isHoistedEnumCaseFetchOnlyForCaseClassPseudoConst($child, $ops, $i, $block)
                    ) {
                        // Lowered via following `Case::class` fold / call-arg compile-time value (#9426, #9518).
                        break;
                    } elseif ($this->isDeferredSiblingInlineCallArgProducer($child, $ops, $i)) {
                        // Hoisted sibling call-arg producers compile at the consumer via
                        // resolveSiblingInlineCallArgProducerSlot (#9463, #10981, #12421, #13788).
                        break;
                    } elseif ($this->isFuncCallLoweredByFollowingEchoConcat($child, $ops, $i)) {
                        break;
                    } elseif (
                        $child instanceof Op\Expr\ConstFetch
                        && $this->isDeferredLeadingConstFetchBeforeSiblingFuncCallConsumer($child, $ops, $i)
                    ) {
                        // explode(PATH_SEPARATOR, get_include_path()) — ConstFetch + sibling FuncCall (#15833).
                        break;
                    } elseif (
                        ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch)
                        && $this->isConstFetchLoweredByFollowingEchoConcatFuncCall($ops, $i)
                    ) {
                        // echo var_export($arr['k'] ?? $d, true) . "\n" — hoisted true before deferred call (#18315).
                        break;
                    } elseif (
                        ($child instanceof Op\Expr\ConstFetch || $child instanceof Op\Expr\ClassConstFetch)
                        && $i + 1 < $opCount
                        && ($ops[$i + 1] instanceof Op\Expr\FuncCall || $ops[$i + 1] instanceof Op\Expr\NsFuncCall)
                        && $this->isDeferredHoistedConstFetchCallArgPrelude($child, $ops[$i + 1], $ops, $i)
                        && !(
                            $child instanceof Op\Expr\ConstFetch
                            && $this->isVarExportReturnFlagAfterPropertyFetchPrelude($child, $ops, $i)
                        )
                    ) {
                        // stream_supports($fp, STREAM_SUPPORT_READ) — FUNCCALL_INIT before const (#17697).
                        break;
                    } elseif ($this->isDeferredTrailingComparatorFirstClassCallable($child, $ops, $i)) {
                        // strcmp(...) trailing FCC with deferred sibling array_keys — emit at consumer (#15475).
                        break;
                    } elseif ($this->isForeachLoopVarAssignRefFusion($ops, $i)) {
                        /** @var Op\Iterator\Value $iter */
                        $iter = $ops[$i];
                        /** @var Op\Expr\AssignRef $assign */
                        $assign = $ops[$i + 1];
                        // Fusion skips AssignRef, which is where rejectThisReassignment normally fires.
                        // Zend zend_compile_foreach: foreach (... as &$this) is Cannot re-assign $this (#32205).
                        $this->rejectThisReassignment($assign->var);
                        // Fusion also skips zend_ensure_writable_variable: foreach (... as &$GLOBALS) (#32229).
                        $this->rejectGlobalsWrite($assign->var, $assign, $block);
                        $destSlot = $this->compileOperand($assign->var, $block, false);
                        $this->registerForeachByRefLoopVarBindings($block, $assign, $iter, $destSlot);
                        $block->addOpCode(new OpCode(
                            OpCode::TYPE_ITER_VALUE,
                            $destSlot,
                            $this->compileOperand($iter->var, $block, true),
                            1
                        ));
                        ++$i;
                        break;
                    } elseif ($this->isForeachListDestructRefFusion($ops, $i, $block)) {
                        /** @var Op\Iterator\Value $iter */
                        $iter = $ops[$i];
                        // Live haystack element for by-ref destructuring slots (#16213, Zend FE_FETCH_R).
                        $block->addOpCode(new OpCode(
                            OpCode::TYPE_ITER_VALUE,
                            $this->compileOperand($iter->result, $block, false),
                            $this->compileOperand($iter->var, $block, true),
                            1
                        ));
                        ++$i;
                        [$block, $i] = $this->compileListDestructGroup($ops, $i, $block);
                        break;
                    } elseif (
                        $child instanceof Op\Expr\PropertyFetch
                        && $i + 1 < $opCount
                        && $this->isPropertyFetchOnlyEmptyVar($child, $ops[$i + 1], $block)
                    ) {
                        // Lowered by compileExpr Empty_ via TYPE_EMPTY_OBJECT_PROPERTY (#4912).
                        break;
                    } elseif (
                        $child instanceof Op\Expr\StaticPropertyFetch
                        && $i + 1 < $opCount
                        && $this->isStaticPropertyFetchOnlyEmptyVar($child, $ops[$i + 1], $block)
                    ) {
                        // Lowered by compileExpr Empty_ via TYPE_EMPTY_STATIC_PROPERTY (#15112, #23983).
                        break;
                    } elseif (
                        (
                            $child instanceof Op\Expr\ArrayDimFetch
                            || $this->isListSpreadAssignOp($child)
                        )
                        && $this->isListDestructGroupStart($ops, $i, $block)
                    ) {
                        [$block, $i] = $this->compileListDestructGroup($ops, $i, $block);
                    } else {
                        if ($this->needsCfgSplitBeforeStringDimFetch($child, $block, $ops, $i)) {
                            $block = $this->splitCfgBlockAfterStringKeyedArray($block);
                        }
                        $echoBlock = $this->compileEchoWithEmbeddedCoalesce($child, $block, $ops, $i);
                        if (null !== $echoBlock) {
                            $block = $echoBlock;
                            break;
                        }
                        if (
                            ($ops[$i + 1] ?? null) instanceof Op\Stmt\JumpIf
                            && null !== ($paramOp = $this->nullableParamFromReturnTernaryArms($ops[$i + 1], $block))
                            && (
                                $child instanceof Op\Expr\BinaryOp\NotIdentical
                                || $child instanceof Op\Expr\BinaryOp\Identical
                            )
                        ) {
                            $this->emitImplicitNullableParamCoalesceReturn($paramOp, $block);
                            break;
                        }
                        if (
                            $child instanceof Op\Expr\BinaryOp\Concat
                            && $this->isConcatLoweredByFollowingEcho($child, $ops, $i)
                        ) {
                            break;
                        }
                        $savedAssignRefFlags = $this->assignRefBindRefFlags;
                        if (
                            $child instanceof Op\Expr\AssignRef
                            && $this->isForeachPropertyHookAssignRefPair($ops, $i)
                        ) {
                            $this->assignRefBindRefFlags = OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK;
                        }
                        $this->emitTicksBeforeStatementIfNeeded($child, $block, $ops, $i);
                        $this->compileOp($child, $block);
                        $this->assignRefBindRefFlags = $savedAssignRefFlags;
                    }
            }
            $this->compileErrorContextOp = $prevCompileErrorContextOp;
        }
    }

    /**
     * String-key array writes and immediate dim fetch in one CFG block break AOT (#764, #783).
     * Keyed list destructuring (`["a" => $x] = …`) is excluded (#1234).
     *
     * @param Op[] $ops
     */
    private function needsCfgSplitBeforeStringDimFetch(Op $op, Block $block, array $ops, int $index): bool
    {
        if (!$op instanceof Op\Expr\ArrayDimFetch) {
            return false;
        }
        if (!$op->dim instanceof Literal || !is_string($op->dim->value)) {
            return false;
        }
        if ($this->isKeyedListDestructDimFetch($ops, $index, $block)) {
            return false;
        }
        // Zend materializes inline array literals before dim-fetch; splitting drops dead temps (#16462).
        if (
            null !== $op->var
            && (
                ($ops[$index - 1] ?? null) instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($op->var, $ops[$index - 1]->result)
            )
            || null !== $this->unwrapArrayLiteralExpr($op->var)
            || null !== $this->findArrayExprForResult($op->var, $block)
        ) {
            return false;
        }
        // Same class of Temporary loss: StaticPropertyFetch emitted just before this dim
        // must stay in the same CFG block. TYPE_JUMP after INIT_ARRAY drops the fetch
        // Temporary and AOT dim-reads empty (#33936 / peer #23354).
        $prev = $ops[$index - 1] ?? null;
        if (
            $prev instanceof Op\Expr\StaticPropertyFetch
            && null !== $op->var
            && $this->operandsReferToSameVariable($op->var, $prev->result)
        ) {
            return false;
        }
        foreach ($block->opCodes as $prevOp) {
            if (OpCode::TYPE_INIT_ARRAY === $prevOp->type && null !== $prevOp->arg3) {
                return true;
            }
            if (OpCode::TYPE_INCLUDE === $prevOp->type && null !== $prevOp->arg2) {
                return true;
            }
        }

        return false;
    }


    private function splitCfgBlockAfterStringKeyedArray(Block $block): Block
    {
        $cont = new Block($block->orig);
        $cont->inheritScopeFrom($block);
        // Temporaries have no name, so findVariableInParentFrames() cannot carry them across the
        // jump; without slot inheritance every value computed before the split reads back empty
        // in the continuation (#23354).
        $cont->inheritUndefinedLocals = true;
        $this->inheritFuncFromParent($cont, $block);
        $jumpToCont = new OpCode(OpCode::TYPE_JUMP);
        $jumpToCont->block1 = $cont;
        $block->addOpCode($jumpToCont);
        $cont->parents[] = $block;

        return $cont;
    }

}
