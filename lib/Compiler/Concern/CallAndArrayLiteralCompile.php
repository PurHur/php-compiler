<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\ClassConstName;
use PHPCompiler\OpCode;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\Web\Superglobals;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\NullOperand;
use PHPCfg\Operand\Temporary;
use PHPTypes\Type;

/**
 * Array-literal + static/method/func-call opcode lowering (#36403 / #36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers enum-case array elements, compileArrayLiteral (incl. element-temp
 * snapshot for nested ?: — #36380 / Zend zend_compile_array), static/method/func
 * call opcode sequences, define()-as-global-const folding, and literal-include
 * caller-local usage marking (php-src Zend/zend_compile.c call / array / const).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types; call-arg
 * and array-element slot wiring relies on coercion (same as FirstClassCallableAndClosure).
 */
trait CallAndArrayLiteralCompile
{
    /**
     * Resolve enum `case` fetches feeding array literals — emit runtime CLASS_CONST_FETCH (#5636).
     *
     * @return list<OpCode>
     */
    protected function compileRuntimeEnumCaseFetchOpsForArrayElement(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex
    ): array {
        $fetch = $this->findEnumCaseClassConstFetchForArrayElement(
            $valueOperand,
            $block,
            $arrayExpr,
            $elementIndex
        );
        if (null === $fetch) {
            return [];
        }
        $valueSlot = $this->compileOperand($valueOperand, $block, true);
        $op = new OpCode(
            OpCode::TYPE_CLASS_CONST_FETCH,
            $valueSlot,
            $this->compileClassNameOperand($fetch->class, $block),
            $this->compileOperand($fetch->name, $block, true)
        );
        $this->assignSourceMetadata($op, $fetch);

        return [$op];
    }

    private function findEnumCaseClassConstFetchForArrayElement(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex,
        bool $forKeyOperand = false
    ): ?Op\Expr\ClassConstFetch {
        $root = $this->unwrapOperandChain($valueOperand);
        if ($root instanceof Op\Expr\ClassConstFetch
            && $this->isCompileTimeEnumCaseClassConstFetch($root, $block)
        ) {
            return $root;
        }
        if (null !== $block->orig) {
            // The Array_ being compiled is the only candidate that matters; walking every
            // prior Array_ child re-did operandsReferToSameVariable O(n) times per element
            // and made nested `[1,2,3]` call stmts O(n²) (#36387).
            foreach ([$arrayExpr] as $child) {
                if (!$child instanceof Op\Expr\Array_) {
                    continue;
                }
                $fetches = $this->precedingClassConstFetchesBeforeCfgOp($block->orig->children, $child);
                $fetches = $this->dropCallArgEnumFetchesBeforeInlineArray($fetches, $child, $block);
                $fetch = $fetches[$elementIndex] ?? null;
                if ($fetch instanceof Op\Expr\ClassConstFetch
                    && $this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)
                ) {
                    if ($this->operandsReferToSameVariable($fetch->result, $valueOperand)) {
                        return $fetch;
                    }
                    // php-cfg may drop the fetch result and leave a literal case-name element
                    // (e.g. `E::A; [ "A", ... ]`) — still treat as enum case fetch (#9039).
                    if ($valueOperand instanceof Operand\Literal && \is_string($valueOperand->value)) {
                        $constName = $this->staticNameFromOperand($fetch->name);
                        if (null !== $constName && $constName === $valueOperand->value) {
                            return $fetch;
                        }
                    }
                    // php-cfg may drop the fetch result and leave a literal backing scalar key
                    // (e.g. `E::A; [ E::A => 1 ]` lowered to key Literal(1)) — recover the enum case fetch (#9024).
                    // Scalar array values must not alias enum backing (#8930, #16316).
                    if ($forKeyOperand
                        && $valueOperand instanceof Operand\Literal
                        && (\is_int($valueOperand->value) || \is_string($valueOperand->value))
                    ) {
                        // Enum-as-key recovery requires key/value literals to match (both the backing scalar).
                        // `[1 => E::B]` / `[1 => 2]` must keep the numeric key (#8930).
                        $elementValue = $arrayExpr->values[$elementIndex] ?? null;
                        if (!$elementValue instanceof Operand\Literal
                            || $elementValue->value !== $valueOperand->value
                        ) {
                            break;
                        }
                        $className = $this->staticNameFromOperand($fetch->class);
                        $constName = $this->staticNameFromOperand($fetch->name);
                        if (null !== $className && null !== $constName) {
                            $lcClass = $this->resolveDefaultClassConstScope($className, $block);
                            $lcConst = ClassConstName::key($constName);
                            $stored = null !== $lcClass
                                ? ($this->compileTimeClassConsts[$lcClass][$lcConst] ?? null)
                                : null;
                            if (null !== $stored) {
                                $stored = $stored->resolveIndirect();
                                $backing = null;
                                if (Variable::TYPE_ENUM_CASE === $stored->type) {
                                    $backing = $stored->toEnumCase()->backingValue->resolveIndirect();
                                } elseif (Variable::TYPE_OBJECT === $stored->type && EnumCaseSupport::isEnumCase($stored->toObject())) {
                                    $backing = $stored->toObject()->enumCaseValue?->resolveIndirect();
                                }
                                if (null !== $backing) {
                                    if (\is_int($valueOperand->value) && Variable::TYPE_INTEGER === $backing->type
                                        && $backing->toInt() === $valueOperand->value
                                    ) {
                                        return $fetch;
                                    }
                                    if (\is_string($valueOperand->value) && Variable::TYPE_STRING === $backing->type
                                        && $backing->toString() === $valueOperand->value
                                    ) {
                                        return $fetch;
                                    }
                                }
                            }
                        }
                    }
                }
                // Hoisted enum fetches may be fewer than mixed scalar/case elements (#16316).
                foreach ($fetches as $fetch) {
                    if (!$fetch instanceof Op\Expr\ClassConstFetch
                        || !$this->isCompileTimeEnumCaseClassConstFetch($fetch, $block)
                    ) {
                        continue;
                    }
                    if ($this->operandsReferToSameVariable($fetch->result, $valueOperand)) {
                        return $fetch;
                    }
                }

                break;
            }
        }

        return null;
    }

    /**
     * in_array(E::A, [1, 2], true) — hoisted needle fetch must not poison int haystack elements (#9888).
     *
     * @param list<Op\Expr\ClassConstFetch> $fetches
     *
     * @return list<Op\Expr\ClassConstFetch>
     */
    private function dropCallArgEnumFetchesBeforeInlineArray(
        array $fetches,
        Op\Expr\Array_ $arrayExpr,
        Block $block
    ): array {
        if ([] === $fetches || null === $block->orig) {
            return $fetches;
        }
        $children = $block->orig->children;
        $arrayIndex = null;
        foreach ($children as $i => $child) {
            if ($child === $arrayExpr) {
                $arrayIndex = $i;
                break;
            }
        }
        if (null === $arrayIndex || $arrayIndex <= 0) {
            return $fetches;
        }
        $preArray = $children[$arrayIndex - 1] ?? null;
        if (!$preArray instanceof Op\Expr\ClassConstFetch) {
            return $fetches;
        }
        for ($i = $arrayIndex + 1, $n = \count($children); $i < $n; ++$i) {
            $next = $children[$i];
            if ($next instanceof Op\Expr\ConstFetch) {
                continue;
            }
            if (!($next instanceof Op\Expr\FuncCall || $next instanceof Op\Expr\NsFuncCall)) {
                return $fetches;
            }
            $callArg0 = $next->args[0] ?? null;
            if ($preArray === ($fetches[0] ?? null)
                && $this->callArgUsesHoistedEnumPreludeSlot($callArg0)
            ) {
                return \array_values(\array_filter(
                    $fetches,
                    static fn (Op\Expr $fetch): bool => $fetch !== $preArray
                ));
            }

            return $fetches;
        }

        return $fetches;
    }

    private function isCompileTimeEnumCaseClassConstFetch(
        Op\Expr\ClassConstFetch $fetch,
        Block $block
    ): bool {
        $className = $this->staticNameFromOperand($fetch->class);
        $constName = $this->staticNameFromOperand($fetch->name);
        if (null === $className || null === $constName) {
            return false;
        }
        $lcClass = $this->resolveDefaultClassConstScope($className, $block);
        if (null === $lcClass) {
            return false;
        }

        return $this->isCompileTimeEnumCaseConstantMember($lcClass, ClassConstName::key($constName));
    }

    /**
     * Fold array element operands, including php-cfg dead ClassConstFetch preludes (#5636).
     */
    protected function tryFoldArrayElementCompileTimeValue(
        Operand $valueOperand,
        Block $block,
        Op\Expr\Array_ $arrayExpr,
        int $elementIndex
    ): ?int {
        $fetch = $this->findEnumCaseClassConstFetchForArrayElement(
            $valueOperand,
            $block,
            $arrayExpr,
            $elementIndex
        );
        if (null !== $fetch) {
            $vm = $this->tryFoldClassConstFetchDefault($fetch, $block, true);
            if (null !== $vm) {
                return $block->registerConstant($valueOperand, $vm);
            }
        }

        return $this->tryFoldCallArgCompileTimeValue($valueOperand, $block);
    }

    /**
     * @return list<OpCode>
     */
    protected function compileArrayLiteral(Op\Expr\Array_ $expr, Block $block): array
    {
        $result = $this->compileOperand($expr->result, $block, false);
        if (empty($expr->values)) {
            return [new OpCode(OpCode::TYPE_INIT_ARRAY, $result)];
        }

        $return = [];
        $started = false;
        $unpackFlags = property_exists($expr, 'unpack') ? $expr->unpack : [];
        $byRefFlags = property_exists($expr, 'byRef') ? $expr->byRef : [];
        for ($i = 0, $n = count($expr->values); $i < $n; ++$i) {
            if (!empty($unpackFlags[$i])) {
                // Zend compile-time IS_CONST unpack of non-array → uncatchable Fatal
                // (zend_compile.c); runtime variables throw catchable Error (#27952).
                $spreadOperand = $expr->values[$i];
                if ($this->isCompileTimeNonTraversableArrayUnpackOperand($spreadOperand)) {
                    $sourceFile = $expr->getFile() ?: ($this->debugLastPhaseInputFile ?? 'unknown');
                    throw new CompileFatal(
                        $sourceFile,
                        max(1, $expr->getLine()),
                        $this->arrayUnpackNonTraversableCompileMessage($spreadOperand)
                    );
                }
                if (!$started) {
                    $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result);
                    $started = true;
                }
                $return[] = new OpCode(
                    OpCode::TYPE_ARRAY_SPREAD,
                    $result,
                    $this->compileOperand($spreadOperand, $block, true),
                    max(0, $expr->getLine())
                );
                continue;
            }

            if (!empty($byRefFlags[$i])) {
                // Reference cells must bind the live lvalue; deferred rematerialization snapshots
                // copy hooked property reads and break set-hook ref writes (#6426, #17353).
                $prefetchOps = $this->compileRuntimeEnumCaseFetchOpsForArrayElement(
                    $expr->values[$i],
                    $block,
                    $expr,
                    $i,
                    !empty($byRefFlags[$i]),
                );
                if ([] !== $prefetchOps) {
                    $valueSlot = $prefetchOps[0]->arg1;
                    $return = array_merge($return, $prefetchOps);
                    $propFetch = null;
                } else {
                    $valueExpr = $expr->values[$i];
                    $propFetch = $this->resolvePropertyFetchForArrayLiteralRef($valueExpr, $block);
                    if (null !== $propFetch) {
                        $valueTemp = new Operand\Temporary();
                        $valueSlot = $block->getVarSlot($valueTemp, false);
                    } else {
                        $valueSlot = $this->compileOperand($valueExpr, $block, true);
                    }
                }
            } else {
                $prefetchOps = $this->compileRuntimeEnumCaseFetchOpsForArrayElement(
                    $expr->values[$i],
                    $block,
                    $expr,
                    $i
                );
                if ([] !== $prefetchOps) {
                    $valueSlot = $prefetchOps[0]->arg1;
                    $return = array_merge($return, $prefetchOps);
                } else {
                    [$rematerializeOps, $valueSlot] = $this->compileDeferredArrayLiteralElementValue(
                        $expr->values[$i],
                        $block,
                        $expr,
                        $i
                    );
                    if ([] !== $rematerializeOps) {
                        $return = array_merge($return, $rematerializeOps);
                    }
                }
                // Copy into a fresh temp before packing. php-cfg may emit a nested ?: JUMPIF
                // for a later element in the same CFG block; that JUMPIF's dead-temp release
                // reused the live element slot, so INIT_ARRAY packed a Variable that the
                // ternary later mutated — Parsedown blockList OOMed on the next dim write
                // (`$Block['data']['markerTypeRegex'] = …`, #36380 / Zend zend_compile_array).
                $snapshotOperand = new Operand\Temporary();
                $snapshotSlot = $block->getVarSlot($snapshotOperand, false);
                $return[] = new OpCode(OpCode::TYPE_ASSIGN, $snapshotSlot, $snapshotSlot, $valueSlot);
                $valueSlot = $snapshotSlot;
                $block->markDeferredArrayLiteralKeepSlot($snapshotSlot);
            }
            $keyOperand = $expr->keys[$i];
            $keyFetch = $this->findEnumCaseClassConstFetchForArrayElement(
                $keyOperand,
                $block,
                $expr,
                $i,
                true
            );
            if (null !== $keyFetch) {
                $keyTemp = new Operand\Temporary();
                $keySlot = $block->getVarSlot($keyTemp, false);
                $keyOp = new OpCode(
                    OpCode::TYPE_CLASS_CONST_FETCH,
                    $keySlot,
                    $this->compileOperand($keyFetch->class, $block, true),
                    $this->compileOperand($keyFetch->name, $block, true)
                );
                $this->assignSourceMetadata($keyOp, $keyFetch);
                $return[] = $keyOp;
            } else {
                $keySlot = $this->compileOperand($keyOperand, $block, true);
            }
            if (!empty($byRefFlags[$i])) {
                if (!$started) {
                    $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result);
                    $started = true;
                }
                $elemTemp = new Operand\Temporary();
                $elemSlot = $block->getVarSlot($elemTemp, false);
                $return[] = new OpCode(
                    OpCode::TYPE_ARRAY_DIM_FETCH_WRITE,
                    $elemSlot,
                    $result,
                    $keySlot instanceof Operand\NullOperand ? null : $keySlot
                );
                if (null !== $propFetch) {
                    ++$this->forcePropertyFetchForWrite;
                    $propWrite = new OpCode(
                        OpCode::TYPE_PROPERTY_FETCH_WRITE,
                        $valueSlot,
                        $this->compileOperand($propFetch->var, $block, true),
                        $this->compileOperand($propFetch->name, $block, true)
                    );
                    $this->assignSourceMetadata($propWrite, $propFetch);
                    $return[] = $propWrite;
                    --$this->forcePropertyFetchForWrite;
                }
                $return[] = new OpCode(
                    OpCode::TYPE_ASSIGN_REF,
                    $elemSlot,
                    $valueSlot
                );
                continue;
            }
            if (!$started) {
                $return[] = new OpCode(OpCode::TYPE_INIT_ARRAY, $result, $valueSlot, $keySlot);
                $started = true;
            } else {
                $return[] = new OpCode(OpCode::TYPE_ADD_ARRAY_ELEMENT, $result, $valueSlot, $keySlot);
            }
        }

        return $return;
    }

    /**
     * StaticCall lowering — mirror compileFuncCall arg partitioning (#23848, re-#17697).
     *
     * Nested StaticCall/FuncCall producers inside args must emit before outer STATICCALL_INIT,
     * same as FUNCCALL_INIT in {@see compileFuncCall()}.
     *
     * @param list<Op\Expr\Argument|Op\Expr\VariadicPlaceholder> $args
     *
     * @return list<OpCode>
     */
    protected function compileStaticCallOpcodes(
        OpCode $init,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null,
        ?string $calleeName = null
    ): array {
        $initPrependedBeforeArgConstFetch = false;
        if (!$this->inlineNestedProducerOpsInArgSends) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }
        $argSends = $this->compileCallArgSends($args, $block, $calleeName, $cfgCallOp);
        if (
            !$initPrependedBeforeArgConstFetch
            && !$this->inlineNestedProducerOpsInArgSends
        ) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }
        [$nestedProducerOps, $outerArgSends] = $this->partitionNestedInlineCallArgProducerOps($argSends);
        $this->rewireArrayBuiltinAdjacentFuncCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireInlineArithmeticBranchCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireSiblingMultiArgInlineCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireNestedMethodCallHoistedClassConstOuterCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireHoistedClassConstPreludeCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireRegisterShutdownFunctionClosureEnumCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireSubstrNestedSprintfArgSendSlots($outerArgSends, $block, $cfgCallOp, $calleeName);
        $this->rewireArrayKeysInlineInitArrayArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $calleeName,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        $this->rewireArrayCombineInlineArgSendSlots($outerArgSends, $block, $argSends, $calleeName, $cfgCallOp);
        $this->rewirePregReplaceCallbackArrayPatternMapArgSendSlots($outerArgSends, $block, $cfgCallOp, $argSends);
        $this->rewireVarExportNestedInlineCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireVarExportComparisonReturnFlagCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireIsArrayNestedFileCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireInlineBitmaskTrailingCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireNamedLocalBeforeInlineBitmaskCallArgSendSlots($outerArgSends, $block, $cfgCallOp);
        $return = [];
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN === $send->type) {
                $return[] = $send;
            }
        }
        foreach ($nestedProducerOps as $op) {
            $return[] = $op;
        }
        if (!$initPrependedBeforeArgConstFetch) {
            $return[] = $init;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN !== $send->type) {
                $return[] = $send;
            }
        }
        $return[] = $this->compileStaticCallExecOpcode($result, $block, $startLine, $cfgCallOp);

        return $return;
    }

    /**
     * StaticCall exec opcode — nested StaticCall arg producers need EXEC_RETURN like FuncCall (#23848).
     */
    protected function compileStaticCallExecOpcode(
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null
    ): OpCode {
        $exec = $this->compileFuncCallExecOpcode($result, $block, $startLine, $cfgCallOp);
        if (
            OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $exec->type
            && (
                $this->callResultFeedsInlineCallArg($result, $block)
                || $this->nestedLiteralPreludeInlineCallProducerNeedsReturnSlot($cfgCallOp, $block)
                || $this->siblingInlineCallArgProducerNeedsReturnSlot($cfgCallOp, $block)
            )
        ) {
            return new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                $this->compileOperand($result, $block, false),
                $startLine > 0 ? $startLine : null
            );
        }

        return $exec;
    }

    protected function compileMethodCallOpcodes(
        ?int $receiver,
        ?int $methodName,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null,
        bool $objectCallInvoke = false
    ): array {
        $argSends = $this->compileCallArgSends($args, $block, null, $cfgCallOp);
        [$nestedProducerOps, $outerArgSends] = $this->partitionNestedInlineCallArgProducerOps($argSends);
        $this->rewireInlineArithmeticBranchCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        // replaceChild(createElement(...), getElementsByTagName(...)->item(0)) — nested MethodCall
        // producers must run before INIT so they do not clobber the outer pending call (#25563).
        // Note: rewireSiblingMultiArgInlineCallArgSendSlots is FuncCall-oriented and can steal
        // createElement's EXEC_RETURN in favor of getElementsByTagName for MethodCall consumers;
        // mixed PropertyFetch+MethodCall ARG_SEND wiring in compileCallArgSends handles DOM cases.
        $return = [];
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN === $send->type) {
                $return[] = $send;
            }
        }
        foreach ($nestedProducerOps as $op) {
            $return[] = $op;
        }
        $init = new OpCode(
            OpCode::TYPE_METHODCALL_INIT,
            $receiver,
            $methodName
        );
        // `$obj(...)` → `__invoke`: Zend object-call handler skips visibility (#26438).
        $init->objectCallInvoke = $objectCallInvoke;
        $return[] = $init;
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN !== $send->type) {
                $return[] = $send;
            }
        }
        $return[] = $this->compileFuncCallExecOpcode($result, $block, $startLine, $cfgCallOp);

        return $return;
    }

    protected function compileFuncCallExecOpcode(
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null
    ): OpCode {
        $line = $startLine > 0 ? $startLine : null;
        if ($this->stmtLevelVoidCallBeforeHoistedArrayConsumerPrelude($cfgCallOp, $block)) {
            return new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
                $line
            );
        }
        if (
            $this->forceDeferredSiblingCallReturnSlot
            || $this->callNeedsReturnSlot($result, $block, $cfgCallOp)
            || $this->cfgCallOpImmediatelyVoidDiscarded($cfgCallOp, $block)
            || $this->siblingInlineCallArgProducerNeedsReturnSlot($cfgCallOp, $block)
            || $this->outerSiblingInlineFuncCallProducerNeedsReturnSlot($cfgCallOp, $block)
            || $this->hoistedSiblingFeedsLaterMultiArgConsumer($cfgCallOp, $block)
            || $this->methodCallDeadTempFeedsLaterMultiArgMethodCall($cfgCallOp, $block)
            || $this->inlineClosurePairHaystackFuncCallNeedsReturnSlot($cfgCallOp, $block)
            || $this->isAdjacentOuterHoistedFuncCallBeforeMultiArgConsumer($cfgCallOp, $block)
            || $this->nestedLiteralPreludeInlineCallProducerNeedsReturnSlot($cfgCallOp, $block)
            || $this->cfgCallIsHoistedArrayKeysForArrayCombine($cfgCallOp, $block)
            || $this->cfgCallImmediatelyFeedsAdjacentConsumer($cfgCallOp, $block)
        ) {
            return new OpCode(
                OpCode::TYPE_FUNCCALL_EXEC_RETURN,
                $this->compileOperand($result, $block, false),
                $line
            );
        }

        return new OpCode(
            OpCode::TYPE_FUNCCALL_EXEC_NORETURN,
            $line
        );
    }

    /**
     * php-cfg `var_dump($g(), $g())` hoists sibling FuncCall producers with dead arg temps (#9463, #10981).
     * Each producer must FUNCCALL_EXEC_RETURN into its result slot before the outer call sends args.
     *
     * @param list<Op> $cfgChildren
     */
    private function siblingInlineCallArgProducerNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (
            null === $cfgCallOp
            || null === $block->orig
            || !$this->isSiblingInlineCallProducerExpr($cfgCallOp)
        ) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp, $block->orig);
        if (!\is_int($producerIndex) || !$cfgCallOp instanceof Op\Expr) {
            return false;
        }
        $n = \count($cfgChildren);
        // Only consumers in the near window after this producer can use it as a hoisted
        // sibling arg — scanning the whole block was O(n²) firstSibling (#36387).
        $scanEnd = min($n, $producerIndex + 1 + 32);
        for ($consumerIndex = $producerIndex + 1; $consumerIndex < $scanEnd; ++$consumerIndex) {
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isInlineExprCallArgConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !is_array($consumer->args) || \count($consumer->args) < 2) {
                if (
                    property_exists($consumer, 'args')
                    && \is_array($consumer->args)
                    && 1 === \count($consumer->args)
                    && $this->isIifeHoistedFuncCallArgProducer(
                        $cfgCallOp,
                        $consumer,
                        $producerIndex,
                        $consumerIndex,
                        $cfgChildren
                    )
                ) {
                    return true;
                }
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if ($this->isNestedCallArgProducerSeparatedByConsumerLiteralPreludes(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return true;
            }
            // substr(sprintf(...), -N) — lone hoisted FuncCall + UnaryMinus offset (#10673, #13801).
            if ($this->isSiblingMultiArgFuncCallProducer(
                $cfgCallOp,
                $consumer,
                $producerIndex,
                $consumerIndex,
                $cfgChildren
            )) {
                return true;
            }
            if (
                null === $firstSibling
                || $this->countSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren) < 2
            ) {
                continue;
            }
            // Multi-sibling chain (MethodCall + FuncCall around ArrayDimFetch) (#28821).
            return true;
        }

        return false;
    }

    /**
     * php-cfg array_intersect(f(g()), f(g())) — outer hoisted producers need EXEC_RETURN (#15488).
     */
    private function outerSiblingInlineFuncCallProducerNeedsReturnSlot(?Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!is_int($producerIndex)) {
            return false;
        }
        for ($consumerIndex = $producerIndex + 1, $n = \count($cfgChildren); $consumerIndex < $n; ++$consumerIndex) {
            // Bound: nested outer producers sit near the consumer (#36387).
            if ($consumerIndex > $producerIndex + 32) {
                break;
            }
            $consumer = $cfgChildren[$consumerIndex] ?? null;
            if (!$this->isSiblingMultiArgInlineCallConsumer($consumer)) {
                continue;
            }
            if (!property_exists($consumer, 'args') || !\is_array($consumer->args) || \count($consumer->args) < 2) {
                continue;
            }
            $firstSibling = $this->firstSiblingInlineFuncCallProducerIndex($consumerIndex, $cfgChildren);
            if (null === $firstSibling || $firstSibling > $producerIndex) {
                continue;
            }
            $outer = $this->outerSiblingInlineFuncCallProducers($firstSibling, $consumerIndex, $cfgChildren);
            $hoistedArgCount = 0;
            foreach ($consumer->args as $callArg) {
                if (null !== $callArg && !$this->isEmbeddedCallLiteralArg($callArg)) {
                    ++$hoistedArgCount;
                }
            }
            if (\count($outer) !== $hoistedArgCount) {
                continue;
            }
            foreach ($outer as $outerProducer) {
                if ($outerProducer === $cfgCallOp) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * createElement(...) before replaceChild(..., getElementsByTagName(...)->item(0)) — php-cfg marks
     * the MethodCall result as a dead temp (empty usages), so the usual sibling-producer predicates
     * miss it and EXEC_NORETURN drops the new node (#25563).
     *
     * Restricted to {@code create*} factory MethodCalls: a bare empty-usages MethodCall before a
     * multi-arg consumer also matches statement {@code loadXML} ahead of
     * {@code importNode(getElementsByTagName()->item(), true)}, which then drops the load and leaves
     * {@code documentElement} null (#25605, re-#20284).
     *
     * @param list<Op> $ops
     */
    private function methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
        Op\Expr\MethodCall $producer,
        array $ops,
        int $producerIndex
    ): bool {
        if ($this->methodCallHasStatementLevelSideEffects($producer)) {
            return false;
        }
        if (null !== $producer->result && !empty($producer->result->usages)) {
            return false;
        }
        // Only DOM/document factory creates are inline dead-temp args for multi-arg MethodCalls
        // (#25563). loadXML/loadHTML/etc. are statement-level even when their bool result is unused
        // (#25605).
        if (!$this->methodCallIsDeadTempCreateFactory($producer)) {
            return false;
        }
        $opCount = \count($ops);
        // Bound: create* factory + property/const preludes + multi-arg MethodCall stay near (#36387).
        $scanEnd = min($opCount, $producerIndex + 1 + 32);
        for ($j = $producerIndex + 1; $j < $scanEnd; ++$j) {
            $next = $ops[$j] ?? null;
            if ($next instanceof Op\Expr\MethodCall || $next instanceof Op\Expr\StaticCall) {
                if (!\is_array($next->args ?? null) || \count($next->args) < 2) {
                    continue;
                }
                $deadTempCount = 0;
                foreach ($next->args as $arg) {
                    if ($this->callArgIsDeadInlineTemporary($arg)) {
                        ++$deadTempCount;
                    }
                }
                if ($deadTempCount >= 2) {
                    return true;
                }
                continue;
            }
            if (
                $next instanceof Op\Expr\PropertyFetch
                || $next instanceof Op\Expr\NullsafePropertyFetch
                || $next instanceof Op\Expr\ConstFetch
                || $next instanceof Op\Expr\ClassConstFetch
                || $next instanceof Op\Expr\FuncCall
                || $next instanceof Op\Expr\NsFuncCall
                || $this->isUnaryInlineSiblingCallArgExpr($next)
            ) {
                continue;
            }
            if ($next instanceof Op && $this->isSiblingInlineCallProducerExpr($next)) {
                continue;
            }
            break;
        }

        return false;
    }

    /**
     * createElement / createTextNode / … — dead-temp factories that feed multi-arg MethodCalls (#25563).
     * Not loadXML / query methods (#25605).
     */
    private function methodCallIsDeadTempCreateFactory(Op\Expr\MethodCall $call): bool
    {
        $method = $this->staticNameFromOperand($call->name);
        if (null === $method) {
            return false;
        }

        return str_starts_with(strtolower($method), 'create');
    }

    /** Block-scoped wrapper for {@see methodCallDeadTempFeedsLaterMultiArgMethodCallInOps} (#25563). */
    private function methodCallDeadTempFeedsLaterMultiArgMethodCall(?Op $cfgCallOp, Block $block): bool
    {
        if (!$cfgCallOp instanceof Op\Expr\MethodCall || null === $block->orig) {
            return false;
        }
        $cfgChildren = $block->orig->children;
        $producerIndex = $this->cfgCallOpIndexInChildren($cfgChildren, $cfgCallOp);
        if (!\is_int($producerIndex)) {
            return false;
        }

        return $this->methodCallDeadTempFeedsLaterMultiArgMethodCallInOps(
            $cfgCallOp,
            $cfgChildren,
            $producerIndex
        );
    }

    protected function compileFuncCall(
        ?int $name,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0,
        ?Op $cfgCallOp = null
    ): array
    {
        $folded = $this->tryCompileDefineAsGlobalConst($name, $args, $result, $block, $startLine);
        if (null !== $folded) {
            return $folded;
        }

        $isDynamicCallee = null !== $cfgCallOp
            && ($cfgCallOp instanceof Op\Expr\FuncCall || $cfgCallOp instanceof Op\Expr\NsFuncCall)
            && $this->funcCallExprUsesVariableCallee($cfgCallOp);

        // Do not fold ForbiddenWhenDynamic names — keep the dynamic flag observable (#23591).
        $foldedName = $this->tryFoldVariableFunctionName($name, $block);
        if (
            null !== $foldedName
            && $isDynamicCallee
            && null !== ($foldedStr = $this->resolveCompileTimeStringSlot($foldedName, $block))
            && VariableFunctionCall::isForbiddenWhenDynamic($foldedStr)
        ) {
            $foldedName = null;
        }
        $callName = $foldedName ?? $name;
        $calleeName = $this->resolveCompileTimeStringSlot($callName, $block)
            ?? ($name !== null ? $this->resolveCompileTimeStringSlot($name, $block) : null);

        $this->lowerEmbeddedCoalesceCallArgs($args, $block);

        $init = new OpCode(
            OpCode::TYPE_FUNCCALL_INIT,
            $callName,
            $startLine > 0 ? $startLine : null
        );
        $init->funcCallDynamic = $isDynamicCallee;
        if (null !== $cfgCallOp) {
            $this->assignSourceMetadata($init, $cfgCallOp);
        }

        $skipPrependForHaystackFamilyDimFetch = false;
        if (null !== $cfgCallOp && \is_array($cfgCallOp->args ?? null)) {
            foreach ($cfgCallOp->args as $argIndex => $callArg) {
                if (!$callArg instanceof Operand) {
                    continue;
                }
                if ($this->callArgIsDeadInlineHaystackFamilySlot(
                    $cfgCallOp,
                    (int) $argIndex,
                    $calleeName,
                    $callArg
                )) {
                    $skipPrependForHaystackFamilyDimFetch = true;
                    break;
                }
            }
        }

        // var_export(fdiv(...), true) — sibling FuncCall producer must INIT/EXEC before consumer (#5471, #4633).
        // Scope to var_export only — broad skip breaks in_array dim-fetch haystack in echo ternary chains (re-#17000, #17851).
        $skipPrependForSiblingFuncProducer = false;
        if (
            null !== $cfgCallOp
            && null !== $block->orig
            && 'var_export' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
        ) {
            $consumerCfgIndex = $this->cfgCallOpIndexInChildren($block->orig->children, $cfgCallOp, $block->orig);
            if (\is_int($consumerCfgIndex)) {
                $skipPrependForSiblingFuncProducer = null !== $this->firstSiblingInlineFuncCallProducerIndex(
                    $consumerCfgIndex,
                    $block->orig->children
                );
            }
        }
        $initPrependedBeforeArgConstFetch = false;
        $skipPrependForExplodeLeadingConstFunc = null !== $cfgCallOp
            && 'explode' === strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? '')
            && null !== $this->leadingConstFetchFuncCallPreludeBeforeCfgCall($cfgCallOp, $block);
        // date_sunrise()/date_sunset() hoisted strtotime + SUNFUNCS_RET_* ConstFetch — producer INIT must run first (#17937).
        $skipPrependForDateSunFunc = null !== $cfgCallOp
            && \in_array(
                strtolower($this->resolveCfgFuncCallName($cfgCallOp) ?? ''),
                ['date_sunrise', 'date_sunset', 'date_sun_info'],
                true
            );
        // json_encode(f(), JSON_FORCE_OBJECT) — hoisted JSON_* ConstFetch must not prepend outer
        // INIT before nested FuncCall producers; AOT walked clobbered arg temps (#34559).
        $skipPrependForJsonEncodeNestedCallArg = null !== $cfgCallOp
            && $this->jsonEncodeDeferredInitForNestedCallArg($cfgCallOp, $block);
        // str_pad/mb_str_pad(…, s(), STR_PAD_*) — same INIT/ConstFetch hoist; VM returned nested value (#34890).
        $skipPrependForStrPadNestedCallArg = null !== $cfgCallOp
            && $this->strPadDeferredInitForNestedCallArg($cfgCallOp, $block);
        if (
            !$skipPrependForSiblingFuncProducer
            && !$skipPrependForHaystackFamilyDimFetch
            && !$skipPrependForExplodeLeadingConstFunc
            && !$skipPrependForDateSunFunc
            && !$skipPrependForJsonEncodeNestedCallArg
            && !$skipPrependForStrPadNestedCallArg
        ) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }

        $argSends = $this->compileCallArgSends($args, $block, $calleeName, $cfgCallOp);
        $argSends = $this->rewriteCallArgSendsForArraySpreadResult($argSends, $block, $cfgCallOp);
        // Hoisted call-arg ConstFetch lands on $block during compileCallArgSends — prepend INIT now (#17697).
        if (
            !$initPrependedBeforeArgConstFetch
            && !$skipPrependForSiblingFuncProducer
            && !$skipPrependForHaystackFamilyDimFetch
            && !$skipPrependForExplodeLeadingConstFunc
            && !$skipPrependForDateSunFunc
            && !$skipPrependForJsonEncodeNestedCallArg
            && !$skipPrependForStrPadNestedCallArg
        ) {
            $initPrependedBeforeArgConstFetch = $this->prependFuncCallInitBeforeTrailingArgConstFetches(
                $block,
                $init
            );
        }
        [$nestedProducerOps, $outerArgSends] = $this->partitionNestedInlineCallArgProducerOps($argSends);
        $this->rewireArrayBuiltinAdjacentFuncCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireInlineArithmeticBranchCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireSiblingMultiArgInlineCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireNestedMethodCallHoistedClassConstOuterCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireHoistedClassConstPreludeCallArgSendSlots($outerArgSends, $block, $cfgCallOp, $nestedProducerOps);
        $this->rewireRegisterShutdownFunctionClosureEnumCallArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $nestedProducerOps
        );
        $this->rewireSubstrNestedSprintfArgSendSlots($outerArgSends, $block, $cfgCallOp, $calleeName);
        $this->rewireArrayKeysInlineInitArrayArgSendSlots(
            $outerArgSends,
            $block,
            $cfgCallOp,
            $calleeName,
            array_merge($nestedProducerOps, $outerArgSends)
        );
        $this->rewireArrayCombineInlineArgSendSlots($outerArgSends, $block, $argSends, $calleeName, $cfgCallOp);
        $this->rewirePregReplaceCallbackArrayPatternMapArgSendSlots($outerArgSends, $block, $cfgCallOp, $argSends);
        $this->rewireVarExportNestedInlineCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireVarExportComparisonReturnFlagCallArgSendSlots(
            $outerArgSends,
            $nestedProducerOps,
            $block,
            $cfgCallOp,
            $calleeName
        );
        $this->rewireIsArrayNestedFileCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp, $calleeName);
        $this->rewireInlineBitmaskTrailingCallArgSendSlots($outerArgSends, $nestedProducerOps, $block, $cfgCallOp);
        $this->rewireNamedLocalBeforeInlineBitmaskCallArgSendSlots($outerArgSends, $block, $cfgCallOp);
        $return = [];
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN === $send->type) {
                $return[] = $send;
            }
        }
        foreach ($nestedProducerOps as $op) {
            $return[] = $op;
        }
        // php-src resolves callee before evaluating call args (#17697); hoisted nested FuncCall
        // producers (ensureDeferredSiblingInlineCallArgProducersCompiled) must run first (#17708).
        if (!$initPrependedBeforeArgConstFetch) {
            $return[] = $init;
        }
        foreach ($outerArgSends as $send) {
            if (OpCode::TYPE_ASSIGN !== $send->type) {
                $return[] = $send;
            }
        }
        $return[] = $this->compileFuncCallExecOpcode($result, $block, $startLine, $cfgCallOp);
        return $return;
    }

    /**
     * Fold $fn = 'name'; $fn(...) to a literal callee when the name is a compile-time string (#56).
     *
     * Follows TYPE_ASSIGN chains so first-class callables (`strlen(...)`, `C::m(...)`, #1363) fold too.
     */
    protected function tryFoldVariableFunctionName(?int $nameSlot, Block $block): ?int
    {
        if (null === $nameSlot) {
            return null;
        }
        $name = $this->resolveCompileTimeStringSlot($nameSlot, $block);
        if (null === $name) {
            return null;
        }
        $lit = new Literal($name);
        $lit->type = Type::string();

        return $this->compileOperand($lit, $block, true);
    }

    /**
     * Resolve a scope slot to a compile-time string via constants or assign chains (#1363).
     */
    protected function resolveCompileTimeStringSlot(int $slot, Block $block, array &$visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (Variable::TYPE_STRING !== $const->type) {
                return null;
            }

            return $const->toString();
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $slot) {
                continue;
            }
            $resolved = $this->resolveCompileTimeStringSlot((int) $op->arg3, $block, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->resolveCompileTimeStringSlot($slot, $parent, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Lower define('NAME', literal) to compile-time global constant registration (issue #204).
     *
     * @return list<OpCode>|null
     */
    protected function tryCompileDefineAsGlobalConst(
        ?int $name,
        array $args,
        Operand $result,
        Block $block,
        int $startLine = 0
    ): ?array {
        if (null === $name) {
            return null;
        }
        $nameOp = $block->getOperand($name);
        if (!$nameOp instanceof Operand\Literal || 'define' !== $nameOp->value) {
            return null;
        }
        if (count($args) < 2 || count($args) > 3) {
            return null;
        }
        $constNameArg = $args[0];
        $valueArg = $args[1];
        if (!$constNameArg instanceof Operand\Literal || !$valueArg instanceof Operand\Literal) {
            return null;
        }
        if (Variable::TYPE_STRING !== Variable::mapFromType($constNameArg->type)) {
            return null;
        }
        $caseInsensitiveSlot = null;
        if (3 === count($args)) {
            $caseInsensitiveArg = $args[2];
            if (!$caseInsensitiveArg instanceof Operand\Literal) {
                return null;
            }
            if (Variable::TYPE_BOOLEAN !== Variable::mapFromType($caseInsensitiveArg->type)) {
                return null;
            }
            $caseInsensitiveSlot = $this->compileOperand($caseInsensitiveArg, $block, true);
            if (!isset($block->constants[$caseInsensitiveSlot])) {
                return null;
            }
        }
        $constNameSlot = $this->compileOperand($constNameArg, $block, true);
        $valueSlot = $this->compileOperand($valueArg, $block, true);
        if (!isset($block->constants[$constNameSlot], $block->constants[$valueSlot])) {
            return null;
        }
        $constName = $block->constants[$constNameSlot]->toString();
        if ('' === $constName || str_contains($constName, '::')) {
            return null;
        }
        // File-scope define() may fold later ConstFetch (#204 / #6542). define() inside a
        // function/method still runs when the function does — do not seed compile-time
        // consts or {main} would see the name before the call (#32039).
        if ($this->compileBlockIsFileScopeMain($block)) {
            $this->storeCompileTimeGlobalConst($constName, $block->constants[$valueSlot]);
        }
        $declare = new OpCode(
            OpCode::TYPE_DECLARE_GLOBAL_CONST,
            $constNameSlot,
            $valueSlot,
            $caseInsensitiveSlot
        );
        if ($startLine > 0) {
            $declare->globalConstStartLine = $startLine;
        }
        $ops = [$declare];
        if (!empty($result->usages)) {
            $trueVar = new Variable(Variable::TYPE_BOOLEAN);
            $trueVar->bool(true);
            $trueOperand = new Temporary;
            $trueOperand->type = Type::bool();
            $trueSlot = $block->registerConstant($trueOperand, $trueVar);
            $ops[] = new OpCode(
                OpCode::TYPE_ASSIGN,
                $this->compileOperand($result, $block, false),
                $this->compileOperand($result, $block, false),
                $trueSlot
            );
        }

        return $ops;
    }

    /**
     * Literal includes read caller locals by name; php-cfg may mark those assigns dead (#568).
     */
    private function markCallerLocalsUsedByLiteralInclude(string $path, Block $block): void
    {
        if (!is_file($path)) {
            return;
        }
        $code = file_get_contents($path);
        if (false === $code || '' === $code) {
            return;
        }
        foreach ($block->scopedOperands() as $operand) {
            $name = OperandName::resolve($operand);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if (!preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                continue;
            }
            if ($operand instanceof Temporary && [] === $operand->usages) {
                $operand->usages[] = $operand;
            }
        }
    }
}
