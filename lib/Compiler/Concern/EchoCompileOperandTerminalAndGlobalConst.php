<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\OpCode;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\AttributeTargetValidator;
use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\Variable;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as CfgVariable;
use PHPTypes\Type;

/**
 * Echo emit slots, compileOperand, compileTerminal, bare-rethrow, and global-const
 * fold helpers (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers freshLiteralConstantSlot / CV occupancy, echo call-result materialization,
 * compileOperand, Terminal_Echo and related tick/rethrow terminals, and
 * compileGlobalConst / tryFoldGlobalConstValueSlot.
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait EchoCompileOperandTerminalAndGlobalConst
{
    /**
     * ?: echo merge phi must not share a slot with method-name literals (#3790, #5506).
     *
     * Clone the PHPCfg Literal before forceFreshVarSlot: AssignOp lowers two
     * PROPERTY_FETCHes that share one Literal object. SplObjectStorage would
     * relocate the first fetch's name slot, leaving try-body arg3 vacant and
     * AOT TypeErroring in getVariableFromOp (#34426, zend_vm_def.h ASSIGN_OBJ_OP).
     */
    private function freshLiteralConstantSlot(Operand $operand, Block $block): int
    {
        if (!$operand instanceof Operand\Literal) {
            return $block->forceFreshVarSlot($operand);
        }
        $fresh = new Operand\Literal($operand->value);
        $fresh->type = $operand->type;
        $mappedType = null !== $fresh->type
            ? Variable::mapFromType($fresh->type)
            : Variable::TYPE_UNDEFINED;
        if ($mappedType === Variable::TYPE_UNDEFINED) {
            if (is_int($fresh->value)) {
                $mappedType = Variable::TYPE_INTEGER;
            } elseif (is_float($fresh->value)) {
                $mappedType = Variable::TYPE_FLOAT;
            } elseif (is_string($fresh->value)) {
                $mappedType = Variable::TYPE_STRING;
            } elseif (is_bool($fresh->value)) {
                $mappedType = Variable::TYPE_BOOLEAN;
            } elseif (null === $fresh->value) {
                $mappedType = Variable::TYPE_NULL;
            }
        }
        $const = new Variable($mappedType);
        switch ($mappedType) {
            case Variable::TYPE_STRING:
                $const->string($fresh->value, true);
                break;
            case Variable::TYPE_INTEGER:
                $const->int($fresh->value);
                break;
            case Variable::TYPE_FLOAT:
                $const->float($fresh->value);
                break;
            case Variable::TYPE_BOOLEAN:
                $const->bool($fresh->value);
                break;
            case Variable::TYPE_NULL:
                break;
            default:
                $this->throwCompileLogic('Unknown Literal Operand Type: ' . ($fresh->type ?? 'untyped'));
        }
        $slot = $block->forceFreshVarSlot($fresh);
        // Same guard as {@see Block::registerConstant}: never alias a named CV (#36380).
        if (
            $block->isNamedAssignDestSlot($slot)
            || $block->isNamedVariableSlot($slot)
            || (null !== $block->functionNamedCvSlots && $this->blockFunctionNamedCvOccupies($block, $slot))
        ) {
            $slot = $block->forceFreshVarSlot($fresh, $slot);
        }
        $block->constants[$slot] = $const;

        return $slot;
    }

    /** @param \ArrayObject<string, int> $_unused */
    private function blockFunctionNamedCvOccupies(Block $block, int $slot): bool
    {
        if (null === $block->functionNamedCvSlots) {
            return false;
        }
        foreach ($block->functionNamedCvSlots as $cvSlot) {
            if ((int) $cvSlot === $slot) {
                return true;
            }
        }

        return false;
    }

    /** @var int Synthetic echo-materialize locals for {main} FuncCall→echo (#23472). */
    private static int $echoFuncCallMaterializeSeq = 0;

    /**
     * Lower `echo f()` in {main} like `$__phpcEchoN = f(); echo $__phpcEchoN` — mirrors named
     * ASSIGN so JIT materializes a stable CV instead of echoing a bare call temp (#23472).
     *
     * Always materialize in {main}: guarding on a later top-level call fixed ~74% of intermittent
     * SIGSEGV but left ~4/100 on consecutive `echo Ack()`; the last echoed call still aliases native
     * call state through teardown.
     */
    private function materializeCallResultSlotBeforeEcho(Block $block, Operand $expr, ?int $slot): ?int
    {
        if (
            null === $slot
            || 0 === $block->nOpCodes
            || !$block->isMainScript()
        ) {
            return $slot;
        }
        $last = $block->opCodes[$block->nOpCodes - 1];
        if (
            OpCode::TYPE_FUNCCALL_EXEC_RETURN !== $last->type
            || (int) $last->arg1 !== $slot
            || !$block->callResultFeedsEcho($expr)
        ) {
            return $slot;
        }
        $name = '__phpcEchoMat' . (++self::$echoFuncCallMaterializeSeq);
        $echoVar = new Operand\Variable(new Operand\Literal($name));
        $srcOp = $block->getOperand($slot);
        if (null !== $srcOp?->type) {
            $echoVar->type = $srcOp->type;
        }
        $destSlot = $block->forceFreshVarSlot($echoVar);
        $resultTemp = new Operand\Temporary();
        if (null !== $srcOp?->type) {
            $resultTemp->type = $srcOp->type;
        }
        $resultSlot = $block->forceFreshVarSlot($resultTemp, $destSlot);
        $block->registerNamedAssignDest($echoVar, $destSlot);
        $block->registerAssignResultLvalue($resultSlot, $destSlot);
        $block->addOpCode(new OpCode(OpCode::TYPE_ASSIGN, $resultSlot, $destSlot, $slot));

        return $destSlot;
    }

    /**
     * Echo must read the live CV slot after ++/-- or assign-op, not a stale literal (#23842).
     */
    private function resolveEchoEmitSlot(Operand $expr, Block $block, ?int $slot): ?int
    {
        if (null === $slot) {
            return null;
        }
        $root = Block::cfgVarRoot($expr);
        if (!$root instanceof Operand\Variable) {
            return $slot;
        }
        $name = Block::resolveVariableName($root);
        if (null === $name || '' === $name) {
            return $slot;
        }
        if (!$block->isMainScript() && !$block->hasLocallyWrittenVariableName($name)) {
            return $slot;
        }
        $live = $block->slotIndexForVariableName($name);
        if (null === $live) {
            return $slot;
        }
        $block->invalidateCompileTimeSlot($live);

        return $live;
    }

    private function attachEchoScriptGlobalName(OpCode $opcode, Operand $expr, Block $block): void
    {
        if (!$block->isMainScript()) {
            return;
        }
        $root = Block::cfgVarRoot($expr);
        if (!$root instanceof Operand\Variable) {
            return;
        }
        $name = Block::resolveVariableName($root);
        if (
            null === $name
            || '' === $name
            || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)
        ) {
            return;
        }
        $opcode->echoScriptGlobalName = $name;
    }

    protected function compileOperand(?Operand $operand, Block $block, bool $isRead): ?int {
        if (null === $operand) {
            return null;
        }
        if ($isRead) {
            $catchSlot = $this->slotForActiveCatchVariable($operand);
            if (null !== $catchSlot) {
                return $catchSlot;
            }
        }
        if ($operand instanceof Operand\NullOperand) {
            return null;
        } elseif ($operand instanceof Operand\Literal) {
            $mappedType = null !== $operand->type
                ? Variable::mapFromType($operand->type)
                : Variable::TYPE_UNDEFINED;
            if ($mappedType === Variable::TYPE_UNDEFINED) {
                if (is_int($operand->value)) {
                    $mappedType = Variable::TYPE_INTEGER;
                } elseif (is_float($operand->value)) {
                    $mappedType = Variable::TYPE_FLOAT;
                } elseif (is_string($operand->value)) {
                    $mappedType = Variable::TYPE_STRING;
                } elseif (is_bool($operand->value)) {
                    $mappedType = Variable::TYPE_BOOLEAN;
                } elseif (null === $operand->value) {
                    $mappedType = Variable::TYPE_NULL;
                }
            }
            $return = new Variable($mappedType);
            switch ($mappedType) {
                case Variable::TYPE_STRING:
                    $return->string($operand->value, true);
                    break;
                case Variable::TYPE_INTEGER:
                    $return->int($operand->value);
                    break;
                case Variable::TYPE_FLOAT:
                    $return->float($operand->value);
                    break;
                case Variable::TYPE_BOOLEAN:
                    $return->bool($operand->value);
                    break;
                case Variable::TYPE_NULL:
                    break;
                default:
                    $this->throwCompileLogic('Unknown Literal Operand Type: ' . ($operand->type ?? 'untyped'));
            }
            // CFG branch blocks inherit parent constant slots; literals must not alias (#15902).
            if ($block->inheritUndefinedLocals) {
                return $this->freshLiteralConstantSlot($operand, $block);
            }

            return $block->registerConstant($operand, $return);
        } elseif ($operand instanceof Operand\Variable) {
            if ($this->isDynamicVariableOperand($operand)) {
                $slot = $block->getVarSlot($operand, $isRead);
                $nameSlot = $this->compileOperand($operand->name, $block, true);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_VAR_FETCH,
                    $slot,
                    $nameSlot
                ));

                return $slot;
            }

            return $this->finalizeOperandSlotForAccess(
                $block,
                $block->getVarSlot($operand, $isRead),
                $isRead
            );
        } elseif ($operand instanceof Operand\Temporary) {
            return $this->finalizeOperandSlotForAccess(
                $block,
                $block->getVarSlot($operand, $isRead),
                $isRead
            );
        }
        $this->throwCompileLogic("Unknown Operand Type: " . $operand->getType());
    }


    protected function compileTerminal(Op\Terminal $terminal, Block $block): array {
        switch ($terminal->getType()) {
            case 'Terminal_Echo':
                $concat = $this->unwrapConcatListExpr($terminal->expr)
                    ?? $this->flattenBinaryConcatToConcatList($terminal->expr);
                if (null !== $concat) {
                    $concat = $this->materializeConcatListCoalesceParts($concat, $block);
                    $this->compileOp($concat, $block);
                    $var = $this->compileOperand($concat->result, $block, true);
                } else {
                    $this->compileEmbeddedExprForOperand($terminal->expr, $block);
                    $var = $this->compileOperand($terminal->expr, $block, true);
                    $var = $this->materializeCallResultSlotBeforeEcho($block, $terminal->expr, $var);
                    $var = $this->resolveEchoEmitSlot($terminal->expr, $block, $var);
                }

                $line = $terminal->getLine();

                $echoOpcode = new OpCode(
                    OpCode::TYPE_ECHO,
                    $var,
                    $line > 0 ? $line : null
                );
                $this->attachEchoScriptGlobalName($echoOpcode, $terminal->expr, $block);

                return [$echoOpcode];
            case 'Terminal_Return':
                $returnLine = $terminal->getLine();
                $returnLineArg = $returnLine > 0 ? $returnLine : null;
                if ($block->returnTypeNever) {
                    $neverFile = $terminal->getFile() ?: 'unknown';
                    $neverLine = $returnLine > 0 ? $returnLine : 1;
                    if ($this->neverFunctionHasAbnormalExitBeforeReturn($block->orig, $terminal)) {
                        return [];
                    }
                    // Arrow expression body → runtime TypeError, not compile Fatal (#30020).
                    if ($this->neverFunctionIsArrowExpressionBody($block)) {
                        return [new OpCode(
                            OpCode::TYPE_RETURN_VOID,
                            $returnLineArg
                        )];
                    }
                    if (!is_null($terminal->expr)) {
                        $this->throwCompileError('A never-returning function must not return', $neverFile, $neverLine);
                    }
                    if ($this->neverFunctionReturnIsImplicitFalloff($terminal)) {
                        return [new OpCode(
                            OpCode::TYPE_RETURN_VOID,
                            $returnLineArg
                        )];
                    }
                    $this->throwCompileError('A never-returning function must not return', $neverFile, $neverLine);
                }
                if (is_null($terminal->expr)) {
                    return [new OpCode(
                        OpCode::TYPE_RETURN_VOID,
                        $returnLineArg
                    )];
                }
                if ($block->returnTypeVoid) {
                    if ($this->voidFunctionReturnIsPhpCfgArtifact($terminal, $block)) {
                        return [new OpCode(
                            OpCode::TYPE_RETURN_VOID,
                            $returnLineArg
                        )];
                    }
                    $this->throwCompileError(
                        $this->voidFunctionReturnValueErrorMessage($terminal->expr, $block)
                    );
                }

                $callResultSlot = $this->funcCallExecReturnSlotForReturn($block, $terminal->expr);
                if (null !== $callResultSlot) {
                    return [new OpCode(OpCode::TYPE_RETURN, $callResultSlot, $returnLineArg)];
                }

                $returnExpr = $terminal->expr;
                while ($returnExpr instanceof Temporary && null !== $returnExpr->original) {
                    $returnExpr = $returnExpr->original;
                }
                if (
                    $returnExpr instanceof CfgVariable
                    && $this->funcReturnTypeIsNullableScalar($block)
                    && $this->operandIsImplicitNullableParam($returnExpr, $block)
                ) {
                    $this->emitImplicitNullableParamCoalesceReturn($returnExpr, $block);

                    return [];
                }

                return [new OpCode(
                    OpCode::TYPE_RETURN,
                    $this->compileOperand($terminal->expr, $block, true),
                    $returnLineArg
                )];
            case 'Iterator_Reset':
                // Stamp foreach site so FE_RESET E_WARNING cites the foreach line (#27953).
                $iterReset = new OpCode(
                    OpCode::TYPE_ITER_RESET,
                    $this->compileOperand($terminal->var, $block, true)
                );
                $this->assignSourceMetadata($iterReset, $terminal);

                return [$iterReset];
            case 'Terminal_Throw':
                if ($this->isBareRethrowThrow($terminal, $block)) {
                    return [new OpCode(OpCode::TYPE_RETHROW)];
                }

                $line = $terminal->getLine();

                return [new OpCode(
                    OpCode::TYPE_THROW,
                    $this->compileOperand($terminal->expr, $block, true),
                    $line > 0 ? $line : null
                )];
            case 'Terminal_Unset':
                $ops = [];
                foreach ($terminal->exprs as $unsetExpr) {
                    $this->rejectThisUnset($unsetExpr);
                    if ($unsetExpr instanceof Operand) {
                        $this->rejectGlobalsWrite($unsetExpr, $terminal, $block);
                        $this->rejectNewExprInWriteContext($unsetExpr, $block, null, null, $terminal);
                        $this->rejectArrayLiteralInWriteContext($unsetExpr, $block, $terminal);
                        $this->rejectGlobalConstInWriteContext($unsetExpr, $block, $terminal);
                        $this->rejectCallReturnInWriteContext($unsetExpr, $block, $terminal);
                    }
                    $staticPropertyFetch = $unsetExpr instanceof Op\Expr\StaticPropertyFetch
                        ? $unsetExpr
                        : ($unsetExpr instanceof Operand ? $this->findStaticPropertyFetchForUnset($unsetExpr, $block) : null);
                    if (null !== $staticPropertyFetch) {
                        $staticUnsetOp = new OpCode(
                            OpCode::TYPE_STATIC_PROPERTY_UNSET,
                            null,
                            $this->compileClassNameOperand($staticPropertyFetch->class, $block),
                            $this->compileStaticPropertyNameSlot(
                                $staticPropertyFetch->name,
                                $staticPropertyFetch->class,
                                $block
                            )
                        );
                        $this->assignSourceMetadata($staticUnsetOp, $terminal);
                        $ops[] = $staticUnsetOp;
                        continue;
                    }
                    // Nested unset($a[0]['k']): FETCH_DIM_W prefixes then UNSET_DIM (#36380).
                    $dimFetch = $unsetExpr instanceof Op\Expr\ArrayDimFetch
                        ? $unsetExpr
                        : ($unsetExpr instanceof Operand
                            ? $this->findCoalesceArrayDimFetch($unsetExpr, $block)
                            : null);
                    if (null !== $dimFetch) {
                        $chain = $this->collectArrayDimFetchChain($dimFetch, $block);
                        [$prefixOps, $containerSlot] = $this->emitUnsetDimWriteChainPrefix($chain, $block);
                        $lastFetch = $chain[count($chain) - 1];
                        $dimSlot = null !== $lastFetch->dim
                            ? $this->compileOperand($lastFetch->dim, $block, true)
                            : null;
                        $unsetOp = new OpCode(
                            OpCode::TYPE_UNSET,
                            null,
                            $containerSlot,
                            $dimSlot
                        );
                        $unsetOp->unsetOnProperty = false;
                        $this->assignSourceMetadata($unsetOp, $terminal);
                        foreach ($prefixOps as $prefixOp) {
                            $ops[] = $prefixOp;
                        }
                        $ops[] = $unsetOp;
                        continue;
                    }
                    [$containerSlot, $dimSlot, $unsetOnProperty] = $this->resolveUnsetTarget($unsetExpr, $block);
                    $unsetOp = new OpCode(
                        OpCode::TYPE_UNSET,
                        null,
                        $containerSlot,
                        $dimSlot
                    );
                    $unsetOp->unsetOnProperty = $unsetOnProperty;
                    // Stamp user site so readonly/unset Errors cite unset() not prior opcodes (#25556).
                    $this->assignSourceMetadata($unsetOp, $terminal);
                    $ops[] = $unsetOp;
                }

                return $ops;
            case 'Terminal_GlobalVar':
                $globalName = $this->resolveSimpleVariableName($terminal->var);
                $this->assertNoThisAsGlobalVariable($globalName, $terminal);
                $nameVar = new Variable(Variable::TYPE_STRING);
                $nameVar->string($globalName);
                $nameOperand = new Operand\Literal($globalName);
                $nameOperand->type = Type::string();
                $nameSlot = $block->registerConstant($nameOperand, $nameVar);
                return [new OpCode(
                    OpCode::TYPE_DECLARE_GLOBAL,
                    $this->compileGlobalImportSlot($terminal->var, $globalName, $block),
                    $nameSlot
                )];
            case 'Terminal_StaticVar':
                throw new \LogicException('StaticVar must be compiled via compileOps (#4352)');
            case 'Terminal_SetTickInterval':
                return $this->compileSetTickInterval($terminal, $block);
            case 'Terminal_LeaveTickInterval':
                return $this->compileLeaveTickInterval($terminal, $block);
            default:
                $this->throwCompileLogic("Unknown Terminal Type: " . $terminal->getType());
        }
    }

    /**
     * @return list<OpCode>
     */
    protected function compileSetTickInterval(Op\Terminal $terminal, Block $block): array
    {
        if (!$terminal instanceof Op\Terminal\SetTickInterval) {
            $this->throwCompileLogic('Expected SetTickInterval terminal');
        }
        $interval = max(0, $terminal->interval);
        $scoped = !empty($terminal->scoped);
        // Braced declare(ticks=N){…} pushes so LeaveTickInterval can restore (#22840).
        // File-level declare uses SET and must persist across CFG jumps (#23486) — never
        // mark tickScopeOpened / auto-LEAVE at block edges (that killed for-loop ticks).
        if ($scoped) {
            $this->activeTickIntervalStack[] = $this->activeTickInterval;
            $this->activeTickInterval = $interval;

            return [new OpCode(OpCode::TYPE_TICK_SCOPE_ENTER, $interval)];
        }
        $this->activeTickInterval = $interval;

        return [new OpCode(OpCode::TYPE_TICK_SCOPE_SET, $interval)];
    }

    /**
     * @return list<OpCode>
     */
    protected function compileLeaveTickInterval(Op\Terminal $terminal, Block $block): array
    {
        if (!$terminal instanceof Op\Terminal\LeaveTickInterval) {
            $this->throwCompileLogic('Expected LeaveTickInterval terminal');
        }
        if ([] !== $this->activeTickIntervalStack) {
            $this->activeTickInterval = array_pop($this->activeTickIntervalStack);
        } else {
            $this->activeTickInterval = 0;
        }

        return [new OpCode(OpCode::TYPE_TICK_SCOPE_LEAVE)];
    }

    /**
     * Zend places ZEND_TICKS on the fallthrough after while/for/do-while (#25621).
     * Insert a synthetic block so the exit path ticks once before successor stmts.
     */
    private function wrapBlockWithLoopExitTick(Block $exit): Block
    {
        $wrapper = new Block(null);
        $wrapper->syntheticCfgBranch = true;
        $wrapper->strictTypes = $exit->strictTypes;
        $wrapper->addOpCode(new OpCode(OpCode::TYPE_TICKS));
        $jump = new OpCode(OpCode::TYPE_JUMP);
        $jump->block1 = $exit;
        $wrapper->addOpCode($jump);

        return $wrapper;
    }

    private function isBareRethrowThrow(Op\Terminal\Throw_ $terminal, Block $block): bool
    {
        if (!$this->isBareRethrowLine($terminal->getLine())) {
            return false;
        }

        return $this->throwOperandIsBareRethrowSentinel($terminal->expr, $block);
    }

    private function isBareRethrowExpression(Op\Expr\Throw_ $expr, Block $block, Block ...$extraSearchBlocks): bool
    {
        if (!$this->isBareRethrowLine($expr->getLine())) {
            return false;
        }

        return $this->throwOperandIsBareRethrowSentinel($expr->expr, $block, ...$extraSearchBlocks);
    }

    private function isBareRethrowLine(int $line): bool
    {
        return $line >= 1 && isset($this->bareRethrowLines[$line]);
    }

    /**
     * SourceBareThrowRewriter lowers bare `throw;` to `throw null`; only that sentinel is a rethrow (#3508, #10016).
     */
    private function throwOperandIsBareRethrowSentinel(?Operand $expr, Block $block, Block ...$extraSearchBlocks): bool
    {
        if (!$expr instanceof Operand) {
            return false;
        }
        $innerOp = $this->findOrigExprOpForOperand($expr, $block);
        if (null === $innerOp) {
            foreach ($extraSearchBlocks as $searchBlock) {
                $innerOp = $this->findOrigExprOpForOperand($expr, $searchBlock);
                if (null !== $innerOp) {
                    break;
                }
            }
        }
        if (!$innerOp instanceof Op\Expr\ConstFetch) {
            return false;
        }
        $name = $this->staticNameFromOperand($innerOp->name);

        return 'null' === strtolower((string) $name);
    }

    /**
     * @return OpCode[]
     */
    protected function compileGlobalConst(Op\Terminal\Const_ $const, Block $block): OpCode
    {
        $this->rejectReservedGlobalConstName($const);
        $this->rejectFinalGlobalTypedConstantIfUnsupported($const);
        $valueSlot = $this->tryFoldGlobalConstValueSlot($const, $block);
        if (null === $valueSlot) {
            $this->compileOps($const->valueBlock->children, $block);
            $valueSlot = $this->compileOperand($const->value, $block, true);
        }
        $constName = $this->staticNameFromOperand($const->name);
        $typeSlot = null;
        if (property_exists($const, 'declaredType') && null !== $const->declaredType) {
            if (!$this->cfgDeclaredTypeIsMixed($const->declaredType)) {
                $declared = $this->typeFromClassConstDecl($const);
                $typeSlot = $this->compileTypeConstrainedVariable($block, $declared, $const->declaredType);
                if (isset($block->constants[$valueSlot])) {
                    $this->verifyGlobalConstCompileTimeType(
                        $const->name,
                        $block->constants[$valueSlot],
                        $typeSlot,
                        $block
                    );
                }
            }
        }
        if (null !== $constName && isset($block->constants[$valueSlot])) {
            $this->storeCompileTimeGlobalConst($constName, $block->constants[$valueSlot]);
        }

        $opcode = new OpCode(
            OpCode::TYPE_DECLARE_GLOBAL_CONST,
            $this->compileOperand($const->name, $block, true),
            $valueSlot
        );
        $opcode->globalConstStartLine = max(0, $const->getLine());
        $opcode->deprecatedMetadata = DeprecatedMetadata::fromOp($const);
        $this->assignAttributeMetadata($opcode, $const);
        AttributeNames::assertAttributeMetaClassTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertCompileTimeConstTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertSensitiveParameterParamTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertReturnTypeWillChangeMethodTargetOnly($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        AttributeNames::assertDeprecatedTargetAllowed($opcode->attributeNames, 'constant', $opcode->attributeEntries);
        // PHP 8.5+ user attributes on file/namespace constants (#23882).
        if (CompilerVersion::supportsAttributeTargetConstant() && [] !== $opcode->attributeEntries) {
            AttributeTargetValidator::assertEntriesForTarget(
                $opcode->attributeEntries,
                \PHPCompiler\VM\AttributeSupport::TARGET_CONSTANT,
                'constant',
                $this->attributeClassRegistry,
                true
            );
        }

        return $opcode;
    }

    protected function tryFoldGlobalConstValueSlot(Op\Terminal\Const_ $terminal, Block $block): ?int
    {
        if (null !== $terminal->valueBlock && [] !== $terminal->valueBlock->children) {
            $children = $terminal->valueBlock->children;
            if (1 === \count($children) && $children[0] instanceof Op\Expr\Array_) {
                $vm = $this->tryBuildCompileTimeArrayFromExpr($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr\ClassConstFetch) {
                $vm = $this->tryFoldClassConstFetchDefault($children[0], $block, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr\ConstFetch) {
                $vm = $this->tryFoldGlobalConstFetch($children[0]);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            if (1 === \count($children) && $children[0] instanceof Op\Expr) {
                $vm = $this->tryFoldCompileTimeExprDefault($children[0], $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
            // Multi-op const-expr (e.g. E::A->value / ->name): php-cfg lowers ClassConstFetch
            // then PropertyFetch; fold like param/property defaults (#19567, zend_compile.c).
            // Global consts emit before DECLARE_ENUM, so runtime CLASS_CONST_FETCH would miss E.
            foreach ($children as $child) {
                if (!$child instanceof Op\Expr) {
                    continue;
                }
                if (!property_exists($child, 'result')
                    || !$this->operandsReferToSameVariable($child->result, $terminal->value)
                ) {
                    continue;
                }
                $vm = $this->tryFoldCompileTimeExprDefault($child, $block, $children, true);
                if (null !== $vm) {
                    return $block->registerConstant(new Operand\Temporary(), $vm);
                }
            }
        }
        $vm = $this->vmVariableFromCfgLiteralOperand($terminal->value);
        if (null === $vm) {
            return null;
        }

        return $block->registerConstant(new Operand\Temporary(), $vm);
    }

    protected function operandsReferToSameVariable(Operand $a, Operand $b): bool
    {
        if ($this->unwrapOperandChain($a) === $this->unwrapOperandChain($b)) {
            return true;
        }
        $rootA = Block::cfgVarRoot($a);
        $rootB = Block::cfgVarRoot($b);
        if (null !== $rootA && null !== $rootB && $rootA === $rootB) {
            return true;
        }
        $nameA = Block::resolveVariableName($a);
        $nameB = Block::resolveVariableName($b);

        return null !== $nameA && '' !== $nameA && $nameA === $nameB;
    }

    protected function unwrapOperandChain(Operand $operand): Operand
    {
        while ($operand instanceof Operand\Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand;
    }

}
