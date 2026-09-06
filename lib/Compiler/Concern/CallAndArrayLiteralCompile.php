<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\ClassConstName;
use PHPCompiler\OpCode;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;

/**
 * Array-literal opcode lowering (#36403 / #36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub can shrink toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers enum-case array elements and compileArrayLiteral (incl. element-temp
 * snapshot for nested ?: — #36380 / Zend zend_compile_array). Static/method/func
 * call opcode sequences live in {@see StaticMethodAndFuncCallCompile}.
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

}
