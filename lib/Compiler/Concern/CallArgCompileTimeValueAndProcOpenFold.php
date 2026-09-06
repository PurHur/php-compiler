<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\ClassConstName;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Compile-time call-arg value fold plus proc_open / array_slice / substr-sprintf
 * inline slot helpers (#36387 / #36403).
 *
 * Extracted from {@see InlineCallArgCompileTimeFold} so gen-0 split-TU can hollow
 * a smaller Concern TU. Mirrors php-src Zend/zend_compile.c (ZEND_SEND_* /
 * compile-time constant folding) and ext/standard proc_open / array_slice arg
 * lowering paths.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * slot wiring relies on coercion (same as InlineCallArgCompileTimeFold).
 */
trait CallArgCompileTimeValueAndProcOpenFold
{
    /**
     * Fold compile-time call arguments, including php-cfg dead ClassConstFetch preludes (#5933).
     */
    protected function tryFoldCallArgCompileTimeValue(
        Operand $arg,
        Block $block,
        ?string $calleeName = null,
        ?Op $cfgCallOp = null
    ): ?int
    {
        if (null !== $this->findCoalesceStmtForCallArg($arg, $block)) {
            return null;
        }
        if (null !== $this->findInlineArrayProducerForCallArg($arg, $block, $cfgCallOp)) {
            return null;
        }
        if ($this->isEmbeddedCallLiteralArg($arg)) {
            return null;
        }
        if ($this->callArgIsNewExpression($arg)) {
            return null;
        }
        // Defense in depth with vmVariableFromCfgLiteralOperand (#28049): never fold $this / CVs.
        if (null !== Block::resolveVariableName($arg)) {
            return null;
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($arg);
        if (null !== $vm) {
            if (Variable::TYPE_STRING === $vm->type) {
                $lc = strtolower($vm->toString());
                if ('true' === $lc || 'false' === $lc) {
                    $bool = new Variable(Variable::TYPE_BOOLEAN);
                    $bool->bool('true' === $lc);
                    $vm = $bool;
                } elseif ('null' === $lc) {
                    $vm = new Variable(Variable::TYPE_NULL);
                } else {
                    $folded = \PHPCompiler\ext\standard\VmPhpCoreConstants::fetch($vm->toString());
                    if (null !== $folded) {
                        $vm = $folded;
                    } else {
                        $errorInt = \PHPCompiler\VM\Context::errorReportingConstant($vm->toString());
                        if (null !== $errorInt) {
                            $intVar = new Variable(Variable::TYPE_INTEGER);
                            $intVar->int($errorInt);
                            $vm = $intVar;
                        } elseif ('inf' === $lc || 'nan' === $lc) {
                            $floatVar = new Variable(Variable::TYPE_FLOAT);
                            $floatVar->float('inf' === $lc ? INF : NAN);
                            $vm = $floatVar;
                        }
                    }
                }
            }

            return $block->registerConstant($arg, $vm);
        }
        $multisortFold = $this->tryFoldArrayMultisortSortingEnumArg($arg, $block, $calleeName, $cfgCallOp);
        if (null !== $multisortFold) {
            return $multisortFold;
        }
        $root = $this->unwrapOperandChain($arg);
        if ($root instanceof Op\Expr\ConstFetch) {
            $vm = $this->tryFoldGlobalConstFetch($root);
            if (null !== $vm) {
                return $block->registerConstant($arg, $vm);
            }
        }
        if ($root instanceof Op\Expr\ClassConstFetch) {
            $vm = $this->tryFoldClassConstFetchDefault($root, $block, true);
            if (null !== $vm) {
                return $block->registerConstant($arg, $vm);
            }
        }
        if (null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null !== $callSite) {
            [$callOp, $argIndex] = $callSite;
            $callArg = $callOp->args[$argIndex] ?? null;
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
            $producer = null;
            if (
                property_exists($callOp, 'args')
                && is_array($callOp->args)
            ) {
                $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
            }
            if ($producer instanceof Op\Expr\ConstFetch) {
                if (null !== $callArg && $this->callArgIsDeadInlineTemporary($callArg)) {
                    foreach ($producers as $candidate) {
                        if (!$candidate instanceof Op\Expr\Cast) {
                            continue;
                        }
                        if ($this->operandsReferToSameVariable($candidate->expr, $producer->result)) {
                            $producer = $candidate;
                            break;
                        }
                    }
                } elseif (null !== $callArg) {
                    $castProducer = $this->matchDirectResultInlineCallArgProducer($producers, $callArg);
                    if ($castProducer instanceof Op\Expr\Cast) {
                        $producer = $castProducer;
                    }
                }
            }
            if ($producer instanceof Op\Expr\ConstFetch) {
                $vm = $this->tryFoldGlobalConstFetch($producer);
                if (null !== $vm) {
                    // php-cfg dead call-arg temp vs hoisted ConstFetch.result (#10453, password_hash PASSWORD_BCRYPT + options).
                    $producerSlot = $block->slotForOperand($producer->result);
                    if (null === $producerSlot) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $block->addOpCode($op);
                        }
                        $producerSlot = $block->slotForOperand($producer->result);
                    }
                    if (null !== $producerSlot) {
                        return $producerSlot;
                    }

                    return $block->registerConstant($producer->result, $vm);
                }
            }
            if ($producer instanceof Op\Expr\BinaryOp) {
                $vm = $this->tryFoldCompileTimeBinaryExprDefault(
                    $producer,
                    $block,
                    $block->orig->children ?? [],
                    true
                );
                if (null !== $vm) {
                    $producerSlot = $block->slotForOperand($producer->result);
                    if (null === $producerSlot) {
                        foreach ($this->compileExpr($producer, $block) as $op) {
                            $block->addOpCode($op);
                        }
                        $producerSlot = $block->slotForOperand($producer->result);
                    }
                    if (null !== $producerSlot) {
                        return $producerSlot;
                    }

                    return $block->registerConstant($producer->result, $vm);
                }
            }
            if ($producer instanceof Op\Expr\Cast) {
                $vm = $this->tryFoldCompileTimeCastDefault(
                    $producer,
                    $block,
                    $block->orig->children,
                    true
                );
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }
                $producerSlot = $block->slotForOperand($producer->result);
                if (null === $producerSlot) {
                    foreach ($this->compileExpr($producer, $block) as $op) {
                        $block->addOpCode($op);
                    }
                    $producerSlot = $block->slotForOperand($producer->result);
                }
                if (null !== $producerSlot) {
                    return $producerSlot;
                }
            }
            if ($producer instanceof Op\Expr\ClassConstFetch) {
                $immediatePropertySlot = $this->slotForImmediatePropertyOrMethodFetchBeforeCfgCall(
                    $block,
                    $callOp,
                    false
                );
                if (null !== $immediatePropertySlot) {
                    return (int) $immediatePropertySlot;
                }
                $producerConst = $this->staticNameFromOperand($producer->name);
                if (null !== $producerConst && 'class' !== strtolower($producerConst)) {
                    foreach ($producers as $later) {
                        if (!$later instanceof Op\Expr\ClassConstFetch) {
                            continue;
                        }
                        $pseudo = $this->staticNameFromOperand($later->name);
                        if (null === $pseudo || 'class' !== strtolower($pseudo)) {
                            continue;
                        }
                        if ($this->operandsReferToSameVariable($later->class, $producer->result)) {
                            $producer = $later;
                            break;
                        }
                    }
                }
                $vm = $this->tryFoldClassConstFetchDefault($producer, $block, true);
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }
            }
            if ($producer instanceof Op\Expr\Closure || $producer instanceof Op\Expr\ArrowFunction) {
                return null;
            }
            if ($producer instanceof Op\Expr\New_) {
                return null;
            }
            if ($producer instanceof Op\Expr\UnaryMinus || $producer instanceof Op\Expr\UnaryPlus) {
                $vm = $this->tryFoldUnaryLiteralDefault($producer);
                if (null !== $vm) {
                    return $block->registerConstant($producer->result, $vm);
                }
            }
            if ($this->callArgOperandIsClosureValue($arg, $block)) {
                return null;
            }
            if ($this->callArgInlineProducerIsNew($callOp, $argIndex, $block)) {
                return null;
            }
            $fetches = $this->precedingCallArgClassConstFetchesBeforeCfgOp($block->orig->children, $callOp, $block);
            $fetch = $this->precedingClassConstFetchForCallArgIndex($callOp, $argIndex, $fetches);
            if ($this->callArgUsesHoistedEnumPreludeSlot($callArg) && $fetch instanceof Op\Expr\ClassConstFetch) {
                $pseudoName = $this->staticNameFromOperand($fetch->name);
                if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
                if ($this->callArgNeedsRuntimeEnumConstFetch($arg, $fetch, $block, $cfgCallOp)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
            }
            $fetch = $this->classConstFetchForHoistedDeadPrelude($callOp, $argIndex, $block);
            if ($this->callArgUsesHoistedEnumPreludeSlot($callArg) && $fetch instanceof Op\Expr\ClassConstFetch) {
                $pseudoName = $this->staticNameFromOperand($fetch->name);
                if (null !== $pseudoName && 'class' === strtolower($pseudoName)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
                if ($this->callArgNeedsRuntimeEnumConstFetch($arg, $fetch, $block, $cfgCallOp)) {
                    $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
                    if (null !== $vm) {
                        return $block->registerConstant($arg, $vm);
                    }
                }
            }
        }
        foreach ($block->orig->children as $child) {
            if ($child instanceof Op\Expr\Array_
                && $this->operandsReferToSameVariable($child->result, $root)
            ) {
                $vm = $this->tryBuildCompileTimeArrayFromExpr($child, $block, [$child]);
                if (null !== $vm) {
                    return $block->registerConstant($arg, $vm);
                }

                continue;
            }
            if (!$child instanceof Op\Expr || !$this->operandsReferToSameVariable($child->result, $root)) {
                continue;
            }
            $vm = $this->tryFoldCompileTimeExprDefault($child, $block, [$child], true);
            if (null !== $vm) {
                return $block->registerConstant($arg, $vm);
            }
        }

        return null;
    }

    /**
     * @param list<Operand> $args
     *
     * @return list<OpCode>
     */
    private function tryFoldArrayMultisortSortingEnumArg(
        Operand $arg,
        Block $block,
        ?string $calleeName,
        ?Op $cfgCallOp = null
    ): ?int {
        if (null === $calleeName || !\in_array(strtolower($calleeName), ['array_multisort', 'sort', 'rsort'], true)) {
            return null;
        }
        if (null === $block->orig) {
            return null;
        }
        $callSite = $this->findCfgCallSiteForArg($block->orig->children, $arg, $cfgCallOp);
        if (null === $callSite) {
            return null;
        }
        [$callOp, $argIndex] = $callSite;
        $fetch = null;
        $root = $this->unwrapOperandChain($arg);
        if ($root instanceof Op\Expr\ClassConstFetch) {
            $fetch = $root;
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            $producers = $this->precedingInlineCallArgProducersBeforeCfgOp($block->orig->children, $callOp);
            if (property_exists($callOp, 'args') && is_array($callOp->args)) {
                $producer = $this->matchInlineCallArgProducer($producers, $callOp->args, $argIndex, $callOp, $block);
                if ($producer instanceof Op\Expr\ClassConstFetch) {
                    $fetch = $producer;
                }
            }
        }
        if (!$fetch instanceof Op\Expr\ClassConstFetch) {
            return null;
        }
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName) {
            return null;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block) ?? strtolower(ltrim($className, '\\'));
        if ('sorting' !== $lcClass || !$this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName))) {
            return null;
        }
        $sortValue = null;
        $lcConst = ClassConstName::key($constName);
        if ('ascending' === $lcConst) {
            $sortValue = SORT_ASC;
        } elseif ('descending' === $lcConst) {
            $sortValue = SORT_DESC;
        }
        if (null === $sortValue) {
            return null;
        }
        $intVar = new Variable(Variable::TYPE_INTEGER);
        $intVar->int($sortValue);

        return $block->registerConstant($arg, $intVar);
    }


    /**
     * in_array()/array_search() haystack — last hoisted Array_ when array_slice also hoists one (#13684, #16084).
     */
    private function matchInlineArraySearchHaystackProducer(array $producers, Operand $haystackArg): ?Op\Expr
    {
        // $arr['k'] ?? [] inline — coalesce merge slot, not ?? RHS empty Array_ (#17980, re-#17000).
        if ([] !== $this->findEmbeddedCoalesces($haystackArg)) {
            return null;
        }
        $arrayProducers = array_values(array_filter(
            $producers,
            static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
        ));
        if ([] === $arrayProducers) {
            return null;
        }
        foreach ($arrayProducers as $producer) {
            if ($this->operandsReferToSameVariable($producer->result, $haystackArg)) {
                return $producer;
            }
        }

        return $arrayProducers[\count($arrayProducers) - 1];
    }

    /**
     * substr(sprintf('%o', fileperms($path)), -N) — haystack is nested sprintf EXEC_RETURN (#16451, #16480).
     */
    private function wireSubstrNestedSprintfCallArgSlot(
        Block $block,
        Op $cfgCallOp,
        int $argIndex,
        ?string $calleeName = null
    ): ?string {
        if (
            0 !== $argIndex
            || 'substr' !== strtolower($this->resolveInlineCallArgFuncName($cfgCallOp, $calleeName) ?? '')
            || null === $block->orig
        ) {
            return null;
        }
        $callIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
        if (!\is_int($callIndex) || $callIndex < 1) {
            return null;
        }
        $cfgChildren = $block->orig->children;
        for ($j = $callIndex - 1; $j >= 0; --$j) {
            $scan = $cfgChildren[$j] ?? null;
            if (
                !($scan instanceof Op\Expr\FuncCall || $scan instanceof Op\Expr\NsFuncCall)
                || !$this->isNestedCallArgProducerForConsumer($scan, $cfgCallOp, $j, $callIndex, $cfgChildren)
                || 0 !== $this->siblingMultiArgFuncCallProducerTargetArgIndex($j, $callIndex, $cfgChildren)
            ) {
                continue;
            }
            $nestedName = $this->resolveCfgFuncCallName($scan) ?? '';

            return $this->slotForSubstrNestedHaystackFuncCallExecReturn(
                $block,
                $j,
                $nestedName,
                $cfgChildren
            );
        }
        $nestedHaystack = $this->substrNestedHaystackFuncCallAtUnaryMinusPattern(
            $cfgCallOp,
            $callIndex,
            $cfgChildren
        );
        if (null === $nestedHaystack) {
            return null;
        }

        return $this->slotForSubstrNestedHaystackFuncCallExecReturn(
            $block,
            $nestedHaystack[0],
            $nestedHaystack[1],
            $cfgChildren
        );
    }

    /**
     * array_slice([..], array_search(...)) — outer Array_ + nested int offset (#13684).
     */
    private function resolveArraySliceInlineCallArgSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        Operand $arg,
        array &$emitOps = []
    ): ?string {
        if (
            null === $cfgCallOp
            || 'array_slice' !== $this->resolveCfgFuncCallName($cfgCallOp)
            || $this->callIncludesNamedParameter($cfgCallOp)
            || !\is_array($cfgCallOp->args ?? null)
            || \count($cfgCallOp->args) < 2
            || null === $block->orig
        ) {
            return null;
        }
        $producers = $this->precedingInlineCallArgProducersBeforeCfgOp(
            $block->orig->children,
            $cfgCallOp
        );
        if (0 === $argIndex) {
            $haystackArg = $cfgCallOp->args[0] ?? $arg;
            if (
                !$haystackArg instanceof Operand
                || !$this->callArgOperandExpectsArrayProducer($haystackArg)
            ) {
                return null;
            }
            $arrayProducers = array_values(array_filter(
                $producers,
                static fn (Op\Expr $producer): bool => $producer instanceof Op\Expr\Array_
            ));
            if ([] === $arrayProducers) {
                return null;
            }
            $arrayExpr = $arrayProducers[0];
            $existingSlot = $block->slotForOperand($arrayExpr->result);
            if (null !== $existingSlot) {
                return (string) $existingSlot;
            }
            $arrayOps = $this->compileArrayLiteral($arrayExpr, $block);
            if ([] !== $arrayOps) {
                foreach ($arrayOps as $op) {
                    $emitOps[] = $op;
                }
            }
            $initSlot = $this->slotFromInitArrayLiteralOps($arrayOps);

            return (string) (
                $initSlot
                ?? $this->firstInitArraySlotInBlock($block)
                ?? '0'
            );
        }
        if (1 !== $argIndex) {
            return null;
        }
        $offsetArg = $cfgCallOp->args[1] ?? $arg;
        // array_slice($b, 1, -2) — embedded literal offset must not steal prior EXEC_RETURN (#10229, #10579).
        if ($this->isEmbeddedCallLiteralArg($offsetArg)) {
            return null;
        }
        if (!$this->callArgIsDeadInlineTemporary($offsetArg)) {
            return null;
        }
        for ($scan = \count($block->opCodes) - 1; $scan >= 0; --$scan) {
            $scanOp = $block->opCodes[$scan];
            if (OpCode::TYPE_FUNCCALL_INIT === $scanOp->type) {
                break;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $scanOp->type && null !== $scanOp->arg1) {
                return (string) $scanOp->arg1;
            }
        }

        return null;
    }

    /**
     * proc_open(['cmd'], $desc, $pipes, null, ['K'=>'V']) — map command/null/env preludes (#9389, #13734).
     * Three-arg inline nested descriptor_spec uses outermost INIT_ARRAY slot (#11485, #11300).
     */
    private function resolveProcOpenInlineCallArgSlot(
        Block $block,
        ?Op $cfgCallOp,
        int $argIndex,
        array $pendingSends = [],
    ): ?string {
        if (
            null === $cfgCallOp
            || 'proc_open' !== $this->resolveCfgFuncCallName($cfgCallOp)
            || $this->callIncludesNamedParameter($cfgCallOp)
            || !\is_array($cfgCallOp->args ?? null)
        ) {
            return null;
        }
        $argCount = \count($cfgCallOp->args);
        if ($argCount >= 3 && $argCount < 5) {
            if (0 === $argIndex) {
                return $this->resolveProcOpenInlineArrayCallArgSlot($block, $cfgCallOp, $argIndex);
            }
            if (1 === $argIndex) {
                $descriptorArg = $cfgCallOp->args[1] ?? null;
                if (
                    $descriptorArg instanceof Operand
                    && $this->callArgIsDeadInlineTemporary($descriptorArg)
                    && $this->callArgOperandExpectsArrayProducer($descriptorArg)
                ) {
                    return $this->resolveInlineArrayProducerSlotBeforeCfgCall($cfgCallOp, $block)
                        ?? $this->resolveOutermostInitArraySlotBeforePendingFuncCall($block, $pendingSends);
                }

                return null;
            }

            return null;
        }
        if ($argCount < 5) {
            return null;
        }

        return match ($argIndex) {
            0, 4 => $this->resolveProcOpenInlineArrayCallArgSlot($block, $cfgCallOp, $argIndex),
            3 => $this->resolveProcOpenNullCwdCallArgSlot($block, $cfgCallOp, $pendingSends),
            default => null,
        };
    }

    /** proc_open inline command/env array literals — map cfg arg operand, not block INIT_ARRAY scan (#16203). */
    private function resolveProcOpenInlineArrayCallArgSlot(Block $block, Op $cfgCallOp, int $argIndex): ?string
    {
        $arrayExpr = $this->procOpenHoistedInlineArrayForArg($cfgCallOp, $argIndex, $block);
        if (!$arrayExpr instanceof Op\Expr\Array_) {
            $callArg = $cfgCallOp->args[$argIndex] ?? null;
            if ($callArg instanceof Operand) {
                $embedded = $this->unwrapArrayLiteralExpr($callArg);
                $arrayExpr = $embedded instanceof Op\Expr\Array_ ? $embedded : null;
            }
        }

        return $this->slotForInlineArrayExpr($block, $arrayExpr);
    }

    /**
     * php-cfg hoists proc_open(['cmd'], …, null, ['K'=>'V']) preludes as sibling stmts (#9389, #16203).
     * Trailing group before the call: command Array_, optional null ConstFetch, env Array_.
     */
    private function procOpenHoistedInlineArrayForArg(Op $callOp, int $argIndex, Block $block): ?Op\Expr\Array_
    {
        if (null === $block->orig || !\is_array($callOp->args ?? null) || \count($callOp->args) < 5) {
            return null;
        }
        if (!\in_array($argIndex, [0, 4], true)) {
            return null;
        }
        $children = $block->orig->children;
        $callIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $callOp) {
                $callIndex = $i;
                break;
            }
        }
        if (null === $callIndex) {
            return null;
        }
        $trail = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $children[$i];
            if ($child instanceof Op\Expr\Array_) {
                array_unshift($trail, $child);
                continue;
            }
            if ($child instanceof Op\Expr\ConstFetch) {
                $name = $this->staticNameFromOperand($child->name);
                if ('null' === $name) {
                    array_unshift($trail, $child);
                    continue;
                }
            }
            if ($child instanceof Op\Expr\Assign) {
                break;
            }
            break;
        }
        $arrays = array_values(array_filter(
            $trail,
            static fn (Op\Expr $expr): bool => $expr instanceof Op\Expr\Array_
        ));
        if (0 === $argIndex) {
            return $arrays[0] ?? null;
        }

        return [] !== $arrays ? $arrays[\count($arrays) - 1] : null;
    }

    /** @param list<OpCode> $pendingSends */
    private function resolveProcOpenNullCwdCallArgSlot(Block $block, ?Op $cfgCallOp, array $pendingSends): ?string
    {
        if (null !== $cfgCallOp && null !== $block->orig) {
            $children = $block->orig->children;
            $callIndex = null;
            foreach ($children as $i => $child) {
                if ($child === $cfgCallOp) {
                    $callIndex = $i;
                    break;
                }
            }
            if (null !== $callIndex) {
                for ($i = $callIndex - 1; $i >= 0; --$i) {
                    $child = $children[$i];
                    if ($child instanceof Op\Expr\ConstFetch && 'null' === $this->staticNameFromOperand($child->name)) {
                        $slot = $block->slotForOperand($child->result);

                        return null !== $slot ? (string) $slot : null;
                    }
                    if ($child instanceof Op\Expr\Array_) {
                        continue;
                    }
                    if ($child instanceof Op\Expr\Assign) {
                        break;
                    }
                    break;
                }
            }
        }
        for ($i = \count($pendingSends) - 1; $i >= 0; --$i) {
            $op = $pendingSends[$i];
            if (OpCode::TYPE_CONST_FETCH !== $op->type || null === $op->arg2) {
                continue;
            }
            $const = $block->constants[$op->arg2] ?? null;
            if (null !== $const && 'null' === $const->toString()) {
                return (string) $op->arg1;
            }
        }

        return null;
    }
}
