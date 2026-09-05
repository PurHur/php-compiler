<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\JIT\Builtin\AttributeRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Func as CoreFunc;
use PHPLLVM;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Core block-lowering loop for JIT/AOT emit.
 *
 * Extracted from {@see \PHPCompiler\JIT} (#36403).
 */
trait CompileBlockInternal
{
    private function compileBlockInternal(
        PHPLLVM\Value $func,
        Block $block,
        ?int $limit = null,
        ?PHPLLVM\BasicBlock $entryBlock = null,
        int $startIndex = 0,
        bool $allowRecompile = false,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        // Before lowering calls: mark leaf-recursive no-throw bodies so invokeJitCall
        // can skip exception-stack + pending-throw checks (#36386 / fibo_r).
        if (null !== $block->func && '{main}' !== $block->func->name) {
            \PHPCompiler\JIT\NoThrowCallElision::analyzeAndRecord(
                $this->context,
                $block,
                strtolower($block->func->getScopedName())
            );
        }
        $this->bindBlockStorageForFunc($func);
        // Builder may still be parked in another LLVM function after NestedJIT / helper emit
        // (#31101). Clear it so we do not continue a foreign instruction stream into $func.
        if ($func instanceof PHPLLVM\Value\Function_) {
            $insert = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context);
            if (null !== $insert) {
                $insertParent = $insert->getParent();
                if (
                    $insertParent instanceof PHPLLVM\Value\Function_
                    && !\PHPCompiler\JIT\TryCatchHelper::sameLlvmFunction($insertParent, $func)
                ) {
                    $this->context->builder->clearInsertionPosition();
                }
            }
        }
        if (
            null !== $block->func
            && '{main}' === $block->func->name
            && $this->isM4BinCompileScriptMain($block)
            && (
                $this->shouldUseM4BinCompileArgvMainNative()
                || $this->shouldUseHelloworldBinCompileInventoryArgvLink()
            )
            && $this->shouldUseM4InventoryArgvNativeEmitRebuild($block)
        ) {
            if (null !== $entryBlock) {
                return $entryBlock;
            }
            $existing = $func->getBasicBlocks();
            if ([] !== $existing) {
                return $existing[0];
            }

            return $func->appendBasicBlock('entry');
        }
        if (!$allowRecompile && $this->context->scope->blockStorage->contains($block)) {
            $cached = $this->context->scope->blockStorage[$block];
            $cachedParent = $cached->getParent();
            // Same CFG Block object may already map to another LLVM function's BB (#31101).
            // Compare via sameLlvmFunction — PHPLLVM wrappers are not ===-stable.
            if (
                $cachedParent instanceof PHPLLVM\Value\Function_
                && \PHPCompiler\JIT\TryCatchHelper::sameLlvmFunction($cachedParent, $func)
                && (null === $entryBlock || $cached === $entryBlock)
            ) {
                return $cached;
            }
        }
        if (null !== $block->func) {
            \PHPCompiler\JIT\Progress::noteFunction($block->func->getScopedName());
        }
        if (null !== $entryBlock) {
            $origBasicBlock = $basicBlock = $entryBlock;
        } else {
            self::$blockNumber++;
            $origBasicBlock = $basicBlock = $func->appendBasicBlock('block_' . self::$blockNumber);
        }
        $storageStale = false;
        if ($this->context->scope->blockStorage->contains($block)) {
            $existingParent = $this->context->scope->blockStorage[$block]->getParent();
            $storageStale = !($existingParent instanceof PHPLLVM\Value\Function_
                && \PHPCompiler\JIT\TryCatchHelper::sameLlvmFunction($existingParent, $func));
        }
        if (
            !$this->context->scope->blockStorage->contains($block)
            || null === $entryBlock
            || $storageStale
            || $allowRecompile
        ) {
            $this->context->scope->blockStorage[$block] = $basicBlock;
        }
        if (
            !$this->context->scope->blockEntryStorage->contains($block)
            || null === $entryBlock
            || $storageStale
            || $allowRecompile
        ) {
            $this->context->scope->blockEntryStorage[$block] = $basicBlock;
        }
        $builder = $this->context->builder;
        $builder->positionAtEnd($basicBlock);
        $this->context->jitCurrentBlock = $block;
        if (null === $this->context->listUnpackAssignRootBlock) {
            foreach ($block->opCodes as $scanOp) {
                if (OpCode::TYPE_LIST_UNPACK_CHECK === $scanOp->type && null !== $scanOp->block1) {
                    $this->context->listUnpackAssignRootBlock = $block;
                    break;
                }
            }
        }
        if (null !== $block->func && $block->orig === $block->func->cfg) {
            $this->context->jitFunctionRootBlock = $block;
            $this->prescanFunctionImportedGlobals($block->func);
            $this->emitJitDestructAllowDelref($block);
        }
        $thisParamOffset = 0;
        if ($this->instanceMethodUsesThis($block) || $this->closureBodyUsesThis($block)) {
            $thisParamOffset = 1;
            if ([] !== $args) {
                $this->context->implicitThisArgument = $args[0];
            } elseif ($func->countParams() > 0) {
                // Nested/on-demand method compile may omit argVars; LLVM param 0 is $this (#16075).
                $this->context->implicitThisArgument = new \PHPCompiler\JIT\Variable(
                    $this->context,
                    \PHPCompiler\JIT\Variable::TYPE_OBJECT,
                    \PHPCompiler\JIT\Variable::KIND_VALUE,
                    $func->getParam(0)
                );
            }
        } else {
            // Static/file-scope/main must not reuse a prior method's LLVM $this param (#31902 AOT).
            $this->context->implicitThisArgument = null;
        }
        // Handle hoisted variables
        if (null !== $block->orig) {
            foreach ($block->orig->hoistedOperands as $operand) {
                if ($this->context->coalesceAssignTargets->contains($operand)) {
                    continue;
                }
                $this->context->makeVariableFromOp($func, $basicBlock, $block, $operand);
            }
        }
        $blockKey = spl_object_id($block);
        if (isset($this->context->listUnpackMergeNullInitTargets[$blockKey])) {
            foreach ($this->context->listUnpackMergeNullInitTargets[$blockKey] as $destOp) {
                if (!$this->context->hasVariableOpInScopes($destOp)) {
                    $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                }
                $dest = $this->context->getVariableFromOpInScopes($destOp);
                if (\PHPCompiler\JIT\Variable::KIND_VARIABLE !== $dest->kind) {
                    continue;
                }
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    \PHPCompiler\JIT\JitValueBox::pointer($this->context, $dest->value)
                );
                $dest->isNullConstant = true;
            }
            unset($this->context->listUnpackMergeNullInitTargets[$blockKey]);
        }
        if (null !== $block->func && $block->orig === $block->func->cfg) {
            $this->context->jitEnclosingBlock = $block;
            $methodLc = strtolower($block->func->name);
            if (str_contains($methodLc, '::')) {
                $methodLc = substr($methodLc, strrpos($methodLc, '::') + 2);
            }
            $this->context->jitPropertyHookRawProperty = \PHPCompiler\SourcePreprocessor\PropertyHooks::propertyNameFromSetHookMethod($methodLc)
                ?? \PHPCompiler\SourcePreprocessor\PropertyHooks::propertyNameFromGetHookMethod($methodLc);
        }
        if ([] !== $args) {
            // SPINE_CHUNK demoted stubs: keep LLVM arity, skip prologue assigns that NestedJIT
            // would still attempt (int ...$types → hashtable into NATIVE_LONG, #36387 Block).
            if (\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()
                && \PHPCompiler\JIT\SpineChunkRuntimeMethodDemote::isDemotedStub($block)
            ) {
                $this->context->implicitThisArgument = null;
            } elseif (0 === $thisParamOffset && $this->llvmThisParamOffset($block) > 0) {
                $thisParamOffset = 1;
            }
            if (!(\PHPCompiler\AOT\ExternalMethodBind::spineChunkMode()
                && \PHPCompiler\JIT\SpineChunkRuntimeMethodDemote::isDemotedStub($block))) {
            // Re-entering the root CFG via coalesce/nullsafe merge ($entryBlock set) must not
            // re-assign `$this` from the LLVM param — that clobbers the live receiver binding
            // after `$this->prop = …` and typed properties read as uninitialized (#36382).
            $isRootCfgReentry = null !== $entryBlock
                && null !== $block->func
                && $block->orig === $block->func->cfg;
            if (!$isRootCfgReentry) {
            foreach ($block->orig->hoistedOperands as $hoisted) {
                if ('this' === \PHPCompiler\JIT\OperandName::resolve($hoisted)) {
                    if (!$this->context->hasVariableOp($hoisted)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $hoisted);
                    }
                    $this->assignOperand($hoisted, $args[0], true);
                    $thisParamOffset = 1;
                    break;
                }
            }
            if (1 === $thisParamOffset) {
                $this->context->implicitThisArgument = $args[0];
            } else {
                $this->context->implicitThisArgument = null;
            }
            }
            // Only the true function entry receives LLVM arguments; branch blocks share the
            // same func (#210). When the root CFG Block is re-entered as a coalesce/nullsafe
            // merge via a pre-created LLVM BB ($entryBlock), skip prologue re-bind — otherwise
            // `$cr = $cr ?? new T` is wiped by writing the original SSA null formal back into
            // the named local before `parent::__construct($rf, $cr)` (#36382 AppFactory).
            if (
                null !== $block->func
                && $block->orig === $block->func->cfg
                && null === $entryBlock
            ) {
                foreach ($block->func->params as $idx => $param) {
                    $argIdx = $thisParamOffset + $idx;
                    if ($param->variadic) {
                        $remaining = array_slice($args, $argIdx);
                        // AOT/Native already pass one packed HT at the variadic slot
                        // (including `&...$args`). Re-capturing that HT and packing it again
                        // nested the array and broke dim write-back (#27407).
                        if (
                            1 === \count($remaining)
                            && Variable::TYPE_HASHTABLE === $remaining[0]->type
                        ) {
                            $packed = $remaining[0];
                        } elseif (isset($block->paramByRef[$idx])) {
                            $refRemaining = [];
                            foreach ($remaining as $arg) {
                                $refRemaining[] = \PHPCompiler\JIT\ClosureHelper::referenceCapture($this->context, $arg);
                            }
                            $packed = [] === $refRemaining
                                ? \PHPCompiler\JIT\HashTableHelper::emptyVariable($this->context)
                                : \PHPCompiler\JIT\HashTableHelper::packVariables($this->context, $refRemaining);
                        } else {
                            $packed = [] === $remaining
                                ? \PHPCompiler\JIT\HashTableHelper::emptyVariable($this->context)
                                : \PHPCompiler\JIT\HashTableHelper::packVariables($this->context, $remaining);
                        }
                        if (!$this->context->hasVariableOp($param->result)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $param->result);
                        }
                        $this->assignOperand($param->result, $packed, true);
                        // `&...$args` prologue: same no-COW marker as ARG_RECV (#34790).
                        if (
                            isset($block->paramByRef[$idx])
                            && $this->context->hasVariableOp($param->result)
                        ) {
                            $this->context->getVariableFromOp($param->result)->borrowedHashtable = true;
                        }
                        break;
                    }
                    if ($argIdx >= count($args)) {
                        break;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $param->result);
                    }
                    if (isset($block->paramByRef[$idx])) {
                        $this->bindJitParamByReference($block, $param->result, $args[$argIdx]);
                    } elseif ($this->storeJitCalleeValueStructFormal($param->result, $args[$argIdx])) {
                        // stored — skip assignOperand copy chain
                    } else {
                        $paramArg = $this->prepareNestedJitCalleeParamArgument($args[$argIdx]);
                        $this->assignOperand($param->result, $paramArg, true);
                    }
                    // Ternary/branch arms use distinct SSA Operand objects for the same CV.
                    // Without a name binding, ARG_SEND in an arm allocates a fresh null
                    // `__value__` box (Temporary fallback) and count() sees null (#27624).
                    $paramName = \PHPCompiler\JIT\OperandName::resolve($param->result);
                    if (
                        null !== $paramName
                        && '' !== $paramName
                        && $this->context->hasVariableOp($param->result)
                    ) {
                        $paramVar = $this->context->getVariableFromOp($param->result);
                        $this->context->bindVariableByName($paramName, $paramVar);
                        $this->syncJitParamVariableToSlotOperands($block, $param->result, $paramVar);
                        // Typed string formals may skip ARG_RECV overwrite (#24137); still
                        // mark assigned so undef-var guards stay quiet (#31101 MiniWebApp).
                        \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                            $this->context,
                            $param->result,
                            $paramVar
                        );
                        // Always mark formals assigned after prologue bind — identity
                        // assignOperand / string ARG_RECV skip can omit the flag and AOT
                        // then warns on every read (MiniWebApp Router::dispatch $route, #31101).
                        \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                            $this->context,
                            $param->result,
                            $this->context->getVariableFromOp($param->result)
                        );
                    }
                }
                $captureSlots = \PHPCompiler\JIT\ClosureHelper::orderedCaptureSlots($block);
                if ([] !== $captureSlots) {
                    $captureBase = count($args) - count($captureSlots);
                    foreach ($captureSlots as $captureIdx => $captureSlot) {
                        $captureOperand = \PHPCompiler\JIT\ClosureHelper::operandForCaptureSlot($block, $captureSlot);
                        if (null === $captureOperand) {
                            continue;
                        }
                        if (!$this->context->hasVariableOp($captureOperand)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $captureOperand);
                        }
                        $captureArg = $args[$captureBase + $captureIdx];
                        $captureVar = $this->context->getVariableFromOp($captureOperand);
                        if (isset($block->closureCaptureByRef[$captureSlot])) {
                            \PHPCompiler\JIT\ClosureHelper::bindCaptureSlotByReference(
                                $this->context,
                                $captureVar,
                                $captureArg
                            );
                        } else {
                            $this->assignOperand($captureOperand, $captureArg, true);
                        }
                        $captureName = $block->closureCaptureSlotNames[$captureSlot] ?? null;
                        if (null !== $captureName && '' !== $captureName) {
                            $this->context->bindVariableByName($captureName, $captureVar);
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $captureOperand,
                                $captureVar
                            );
                        }
                    }
                }
            }
            } // !isDemotedStub — keep LLVM arity, skip NestedJIT prologue assigns (#36387)
        }

        for ($i = $startIndex, $length = null !== $limit ? $limit : count($block->opCodes); $i < $length; ++$i) {
            $op = $block->opCodes[$i];
            $this->context->retainCoalesceInstancePropertyLvalue = false;
            // Current opline for runtime warnings (encapsed CONCAT FETCH_R, #32034).
            if (null !== $op->sourceLocation && $op->sourceLocation->startLine > 0) {
                $this->context->callSiteLine = $op->sourceLocation->startLine;
            }
            if (null !== $block->func) {
                // {main} always; methods when PHP_COMPILER_JIT_OP_PROGRESS=1 (Slim Uri::withUserInfo
                // stalls for minutes before preg_replace_callback — need opcode breadcrumbs, #36382).
                $opProgress = '{main}' === $block->func->name;
                if (!$opProgress) {
                    $opEnv = \PHPCompiler\Config::getenv('PHP_COMPILER_JIT_OP_PROGRESS');
                    if (is_string($opEnv) && '' !== $opEnv) {
                        $v = strtolower($opEnv);
                        $opProgress = in_array($v, ['1', 'true', 'yes', 'on'], true);
                    }
                }
                if ($opProgress) {
                    $scoped = $block->func->getScopedName();
                    \PHPCompiler\JIT\Progress::noteFunction($scoped.':op='.$i.':type='.$op->type);
                }
            }
            // Folded TYPE_ENUM_CASE slots (script CLASS_CONST_FETCH is often eliminated)
            // hoist as null value-boxes before DECLARE_ENUM. Rebind now that enums exist (#31967).
            $this->rebindEnumCaseConstantSlots($block, $op);
            switch ($op->type) {
                case OpCode::TYPE_ARG_RECV:
                    $this->compileArgRecvOp($block, $op, $thisParamOffset, ...$args);
                    break;
                case OpCode::TYPE_ASSIGN:
                    $this->compileAssignOp($block, $op, $i, $func, $basicBlock, $thisParamOffset, ...$args);
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $this->compileAssignRefOp($block, $op);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
                case OpCode::TYPE_VAR_FETCH:
                    $this->compileDeclareGlobalStaticAndVarFetchOp(
                        $block,
                        $op,
                        $i,
                        $func,
                        $basicBlock,
                        $builder,
                        ...$args
                    );
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $this->compileArrayDimFetchOp($block, $op, $i, $func, $basicBlock);
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                case OpCode::TYPE_ARRAY_SPREAD:
                    $this->compileInitArrayFamilyOp($block, $op, $i);
                    break;
                case OpCode::TYPE_LIST_UNPACK_CHECK:
                case OpCode::TYPE_LIST_SPREAD_ASSIGN:
                    $this->compileListUnpackOp($block, $op, $func);
                    break;
                case OpCode::TYPE_TYPE_ASSERT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_EMPTY:
                case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
                case OpCode::TYPE_EMPTY_STATIC_PROPERTY:
                case OpCode::TYPE_EMPTY_DIMENSION:
                case OpCode::TYPE_EVAL:
                case OpCode::TYPE_ISSET:
                    $this->compileEmptyIssetEvalOp($block, $op, $func);
                    break;
                case OpCode::TYPE_ITER_RESET:
                case OpCode::TYPE_ITER_VALID:
                case OpCode::TYPE_ITER_KEY:
                case OpCode::TYPE_ITER_VALUE:
                    $this->compileIterOp($block, $op);
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                case OpCode::TYPE_INCLUDE:
                case OpCode::TYPE_CLONE:
                    $this->compileScriptMagicIncludeCloneOp($block, $op, $func);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                case OpCode::TYPE_CONST_FETCH:
                case OpCode::TYPE_INSTANCEOF:
                case OpCode::TYPE_IN:
                    $this->compileConstFetchBooleanNotAndInstanceofOp($block, $op);
                    break;
                case OpCode::TYPE_CONCAT:
                    $this->compileConcatOp($block, $op, $i, $func);
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $this->compileClassConstFetchOp($block, $op);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                case OpCode::TYPE_UNSET:
                    $this->compileStaticPropertyOrUnsetOp($block, $op, $i, $func, $basicBlock);
                    break;
                case OpCode::TYPE_CAST_BOOL:
                case OpCode::TYPE_CAST_INT:
                case OpCode::TYPE_CAST_FLOAT:
                case OpCode::TYPE_CAST_STRING:
                case OpCode::TYPE_CAST_VOID:
                case OpCode::TYPE_CAST_ARRAY:
                case OpCode::TYPE_CAST_OBJECT:
                case OpCode::TYPE_CAST_UNSET:
                    $this->compileCastOp($block, $op);
                    break;
                case OpCode::TYPE_ECHO:
                case OpCode::TYPE_PRINT:
                    $this->compileEchoOrPrintOp($block, $op, $i, $func, $basicBlock);
                    break;
                case OpCode::TYPE_EXIT:
                    $this->compileExitOp($block, $op);
                    break;
                case OpCode::TYPE_POW:
                    $this->compilePowOp($block, $op);
                    break;
                case OpCode::TYPE_POST_INC:
                    $this->compileIncDecOp($block, $op, true, false);
                    break;
                case OpCode::TYPE_PRE_INC:
                    $this->compileIncDecOp($block, $op, true, true);
                    break;
                case OpCode::TYPE_POST_DEC:
                    $this->compileIncDecOp($block, $op, false, false);
                    break;
                case OpCode::TYPE_PRE_DEC:
                    $this->compileIncDecOp($block, $op, false, true);
                    break;
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_LOGICAL_XOR:
                case OpCode::TYPE_SPACESHIP:
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_BITWISE_NOT:
                case OpCode::TYPE_UNARY_PLUS:
                    $this->compileBinaryAndUnaryOp($block, $op);
                    break;
                case OpCode::TYPE_CASE:
                    $this->compileCaseOp($block, $op, $func, $builder, ...$args);
                    break;
                case OpCode::TYPE_JUMP:
                    return $this->compileJumpOp($block, $op, $func, $builder, $origBasicBlock, ...$args);
                case OpCode::TYPE_COALESCE:
                    $coalesceCont = $this->compileCoalesceOp(
                        $block,
                        $op,
                        $func,
                        $builder,
                        $origBasicBlock,
                        ...$args
                    );
                    if (null !== $coalesceCont) {
                        return $coalesceCont;
                    }
                    break;
                case OpCode::TYPE_NULLSAFE:
                    $nullsafeCont = $this->compileNullsafeOp(
                        $block,
                        $op,
                        $func,
                        $builder,
                        $origBasicBlock,
                        ...$args
                    );
                    if (null !== $nullsafeCont) {
                        return $nullsafeCont;
                    }
                    break;
                case OpCode::TYPE_JUMPIF:
                    return $this->compileJumpIfOp($block, $op, $i, $func, $origBasicBlock, ...$args);
                case OpCode::TYPE_TRY:
                    \PHPCompiler\JIT\TryCatchHelper::beginTry($this, $func, $this->context, $block, $op, $i, $args);

                    return $origBasicBlock;
                case OpCode::TYPE_CATCH:
                    if ([] !== $this->context->tryCatch->handlerStack) {
                        \PHPCompiler\JIT\TryCatchHelper::finishPostTryOpcode($this->context);
                        break;
                    }
                    if (null !== $op->block1) {
                        $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_FINALLY:
                    if ([] !== $this->context->tryCatch->handlerStack) {
                        \PHPCompiler\JIT\TryCatchHelper::finishPostTryOpcode($this->context);
                        break;
                    }
                    if (null !== $op->block1) {
                        $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_THROW:
                    \PHPCompiler\JIT\TryCatchHelper::emitThrow($this, $this->context, $func, $block, $op);

                    return $origBasicBlock;
                case OpCode::TYPE_RETHROW:
                    \PHPCompiler\JIT\TryCatchHelper::emitRethrow($this, $this->context, $func, $block);

                    return $origBasicBlock;
                case OpCode::TYPE_RETURN_VOID:
                case OpCode::TYPE_RETURN:
                    return $this->compileReturnOp($block, $op, $func, $builder, $origBasicBlock);
                case OpCode::TYPE_FUNCDEF:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    // compileBlock() sets activeFunction for the nested Func; restore the
                    // enclosing frame so call-site DnfParamCheck in {main} aborts (#29859),
                    // not pend+return like a callee-body throw (#33971 / #33972 regression).
                    $savedActiveFunction = $this->context->activeFunction;
                    $this->compileBlock($op->block1, $nameOp->value);
                    $this->context->activeFunction = $savedActiveFunction;
                    break;
                case OpCode::TYPE_CLOSURE:
                    if ($this->shouldStubClosureLowering() || null === $op->block1) {
                        // Bootstrap / vendor prelink: closures are not executable yet; represent as null.
                        $nullVar = new Variable(
                            $this->context,
                            Variable::TYPE_NULL,
                            Variable::KIND_VALUE,
                            $this->context->getTypeFromString('__value__*')->constNull()
                        );
                        $nullVar->isNullConstant = true;
                        $this->assignOperandValue($block->getOperand($op->arg1), $nullVar->value);
                        break;
                    }
                    // Mirror VM TYPE_CLOSURE: definition-site class scope on the nested Func so
                    // self::class / __CLASS__→self::class lower during AOT (#26459, #25793).
                    if (\PHPCompiler\JIT\FiberHelper::blockContainsFiberSuspend($op->block1)) {
                        $this->propagateEnclosingClassOntoClosureFunc($block, $op->block1);
                        $internalName = \PHPCompiler\JIT\ClosureHelper::nextInternalName();
                        $resumeName = strtolower($internalName.'__fiber_resume');
                        \PHPCompiler\JIT\FiberHelper::compileResumeFunction(
                            $this,
                            $resumeName,
                            $op->block1,
                            $internalName
                        );
                        $this->context->scriptFiberResumeName = $resumeName;
                        $closureObj = \PHPCompiler\JIT\FiberHelper::allocateFiberCallbackObject(
                            $this->context,
                            $resumeName
                        );
                        $this->assignOperand($block->getOperand($op->arg1), $closureObj, true);
                        break;
                    }
                    $internalName = $op->closurePrecompiledInternalName
                        ?? \PHPCompiler\JIT\ClosureHelper::nextInternalName();
                    if (null === $op->closurePrecompiledInternalName) {
                        $this->compileClosureBodyBlock($block, $op->block1, $internalName);
                    }
                    $lcname = strtolower($internalName);
                    if (!isset($this->context->functionProxies[$lcname])) {
                        throw new \LogicException("Closure body failed to register JIT proxy: {$internalName}");
                    }
                    $callProxy = $this->context->functionProxies[$lcname];
                    if ([] !== $op->closureCaptures) {
                        $captures = \PHPCompiler\JIT\ClosureHelper::snapshotCapturesForClosure(
                            $this->context,
                            $op->block1,
                            $op->closureCaptures
                        );
                        $callProxy = \PHPCompiler\JIT\ClosureHelper::wrapCallWithCaptures($callProxy, $captures);
                    }
                    // Bound $this/scope slots must exist before allocate — otherwise
                    // storeInstanceProperty writes past the object / fetch reads null
                    // and cross-function `$f = $obj->m(); $f()` loses $this (#35456).
                    if (null !== $block->func && null !== $block->func->class) {
                        \PHPCompiler\JIT\ClosureBindHelper::ensureClosureBindingProperties($this->context);
                    }
                    $closureObj = \PHPCompiler\JIT\ClosureHelper::allocateClosureObject(
                        $this->context,
                        $callProxy,
                        $internalName
                    );
                    $isStaticClosure = null !== $op->block1->func
                        && (($op->block1->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
                    if ($isStaticClosure) {
                        $closureObj->closureIsStatic = true;
                        \PHPCompiler\JIT\ClosureBindHelper::storeStaticClosureFlag(
                            $this->context,
                            $this->context->helper->loadValue($closureObj)
                        );
                    }
                    if (null !== $block->func && null !== $block->func->class) {
                        $scopeName = (string) $block->func->class->value;
                        $scopeLc = strtolower(ltrim($scopeName, '\\'));
                        // Trait method closures: bind ce to the composing class (#26459, #25793).
                        if ($this->context->type->object->isTraitClass($scopeLc)) {
                            $composing = $this->context->scope->traitComposingClassName;
                            if ('' === $composing) {
                                $composing = $this->context->scope->className;
                            }
                            if ('' !== $composing
                                && !$this->context->type->object->isTraitClass(strtolower(ltrim($composing, '\\')))) {
                                if ($this->context->type->object->hasDeclaredClass($composing)) {
                                    $scopeName = $this->context->type->object->classNameForId(
                                        $this->context->type->object->lookup($composing)
                                    );
                                } else {
                                    $scopeName = $composing;
                                }
                            }
                        }
                        $boundScope = new Variable(
                            $this->context,
                            Variable::TYPE_STRING,
                            Variable::KIND_VALUE,
                            $this->context->builder->load(
                                $this->context->constantStringFromString($scopeName)
                            )
                        );
                        $boundScope->compileTimeString = $scopeName;

                        // Store the live TYPE_OBJECT $this into Closure's TYPE_OBJECT
                        // __closure_bound_this slot (Object_ seeds TYPE_OBJECT — a VALUE box
                        // store corrupts the pointer slot and cross-function reload sees null).
                        // Create-time ClosureWithBinding still uses a snapshot for in-method
                        // `$g()` (#28612); invoke after return reloads via closureObject /
                        // RuntimeIndirect (#35456).
                        $boundThis = \PHPCompiler\JIT\ClosureHelper::nullCapture($this->context);
                        $boundThisForSlot = $boundThis;
                        if (!$isStaticClosure) {
                            $thisVar = $this->resolveThisVariable($block)
                                ?? $this->context->variableForScopedName('this');
                            if (null !== $thisVar) {
                                $boundThis = \PHPCompiler\JIT\ClosureHelper::snapshotCapture($this->context, $thisVar);
                                if (Variable::TYPE_OBJECT === $thisVar->type) {
                                    $boundThisForSlot = $thisVar;
                                } else {
                                    $objPtr = $this->context->builder->call(
                                        $this->context->lookupFunction('__value__readObject'),
                                        \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $thisVar)
                                    );
                                    $boundThisForSlot = new Variable(
                                        $this->context,
                                        Variable::TYPE_OBJECT,
                                        Variable::KIND_VALUE,
                                        $objPtr
                                    );
                                    $boundThisForSlot->addref();
                                }
                            }
                        }

                        $obj = $this->context->helper->loadValue($closureObj);
                        \PHPCompiler\JIT\ClosureBindHelper::storeFccBoundThisAndScope(
                            $this->context,
                            $obj,
                            $boundThisForSlot,
                            $boundScope
                        );
                        $closureObj->closureCall = new \PHPCompiler\JIT\Call\ClosureWithBinding(
                            $callProxy,
                            $boundThis,
                            $boundScope
                        );
                        $this->context->lastClosureCallProxy = $closureObj->closureCall;
                    }
                    // Refresh returned-closure map with the final proxy (captures / binding) (#34868).
                    if (null !== $closureObj->closureCall) {
                        $this->recordReturnedClosureProxyForBlock($block, $closureObj->closureCall);
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $closureObj, true);
                    break;
                case OpCode::TYPE_YIELD:
                case OpCode::TYPE_YIELD_FROM:
                    if ($this->context->compilingGeneratorResume) {
                        $yieldId = spl_object_id($op);
                        if (!isset($this->context->generatorYieldPointIndex[$yieldId])) {
                            throw new \LogicException('yield opcode missing from resume-point index (#35142)');
                        }
                        $pointIndex = $this->context->generatorYieldPointIndex[$yieldId];
                        $stateParam = $this->context->generatorStateParam;
                        assert(null !== $stateParam);
                        if (OpCode::TYPE_YIELD_FROM === $op->type) {
                            \PHPCompiler\VM\GeneratorYieldFromJitHelper::emitYieldFromPoint(
                                $this,
                                $block,
                                $op,
                                $stateParam,
                                $pointIndex
                            );
                        } else {
                            \PHPCompiler\VM\GeneratorIteratorJitHelper::emitYieldPoint(
                                $this,
                                $block,
                                $op,
                                $stateParam,
                                $pointIndex + 1
                            );
                        }
                        $contIp = $pointIndex + 1;
                        if (!isset($this->context->generatorResumeContinuations[$contIp])) {
                            throw new \LogicException('generator resume continuation missing for ip '.$contIp.' (#35142)');
                        }
                        $cont = $this->context->generatorResumeContinuations[$contIp];
                        $this->context->builder->positionAtEnd($cont);
                        $basicBlock = $cont;
                        $origBasicBlock = $cont;
                        break;
                    }
                    throw new \LogicException('Generators (yield) are VM-only (issue #167)');
                case OpCode::TYPE_FUNCCALL_INIT:
                    $this->compileFuncCallInitOp($block, $op);
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    // Nested `new T(self::make(), …)` / AppFactory::create: STATICCALL_INIT
                    // must save the pending TYPE_NEW construct like FUNCCALL_INIT (#36382).
                    $this->saveJitPendingOutboundCall();
                    $this->initJitStaticCall($block, $op->arg1, $op->arg2, $op->staticCallParentScope);
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $this->compileArgSendOp($block, $op);
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    $this->compileFuncCallExecNoreturnOp($block, $op, $i, $func, $basicBlock);
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    $this->compileFuncCallExecReturnOp($block, $op, $i, $func, $basicBlock);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    if (isset($block->constants[$op->arg2])) {
                        $constValue = new \PHPCompiler\VM\Variable();
                        $constValue->copyFrom($block->constants[$op->arg2]);
                    } else {
                        if ($this->shouldUseSelfHostJitStubs()) {
                            break;
                        }
                        $vm = new VM($this->context->runtime->vmContext);
                        // Seed user classes for `const C = new UserClass` — MODE_AOT skips
                        // VM DECLARE_CLASS, same gap class-const materialization fixed (#19046 / #35196).
                        $rootBlock = $this->context->jitFunctionRootBlock
                            ?? $this->context->jitEnclosingBlock
                            ?? $block;
                        \PHPCompiler\VM\ClassConstMaterializer::seedReferencedClasses(
                            $vm,
                            $rootBlock,
                            $block,
                            $op->arg2
                        );
                        $constValue = \PHPCompiler\VM\ClassConstMaterializer::materializeGlobalConstSlot(
                            $vm,
                            $block,
                            $op->arg2
                        );
                    }
                    $constValue = \PHPCompiler\VM\EnumCaseSupport::materializeConstantValue(
                        $this->context->runtime->vmContext,
                        $constValue
                    );
                    if ($this->context->runtime->vmContext->defineConstant(
                        $nameOp->value,
                        $constValue
                    )) {
                        $this->registeredGlobalConstDeclareOpcodes->attach($op);
                        if (\PHPCompiler\VM\Variable::TYPE_ARRAY === $constValue->type) {
                            $this->context->constantArrayFromVmHashTable(
                                $nameOp->value,
                                $constValue->toArray()
                            );
                        } elseif (
                            \PHPCompiler\VM\Variable::TYPE_OBJECT === $constValue->type
                            && !\PHPCompiler\VM\EnumCaseSupport::isEnumCaseVariable($constValue)
                        ) {
                            // Bake immortal object for later CONST_FETCH (#35196, peer #34783).
                            $this->context->constantObjectFromVm($nameOp->value, $constValue);
                        }
                        break;
                    }
                    // Spine may require bin/vm.php after tokenizer-compat shims (#2134).
                    if ($this->shouldUseSelfHostJitStubs()) {
                        break;
                    }
                    // Re-compile passes (jitCompileBlock + runQueue) may revisit DECLARE_GLOBAL_CONST (#4941).
                    if ($this->registeredGlobalConstDeclareOpcodes->contains($op)) {
                        break;
                    }
                    $scriptPath = $block->scriptPath();
                    $line = (int) ($op->globalConstStartLine ?? 0);
                    $this->context->runtime->vmContext->errors->triggerError(
                        "Constant {$nameOp->value} already defined",
                        \PHPCompiler\VM\ErrorReporter::E_WARNING,
                        null !== $scriptPath && '' !== $scriptPath ? $scriptPath : null,
                        $this->context->runtime->vmContext,
                        null,
                        $line > 0 ? $line : 0
                    );
                    break;
                case OpCode::TYPE_DECLARE_INTERFACE:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'interface', $nameOp->value)) {
                        break;
                    }
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->type->object->setClassSourceLocation(
                        $this->context->scope->classId,
                        $op->sourceLocation
                    );
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->context->type->object->markInterfaceClass($nameOp->value);
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setInterfaceExtends(
                            $nameOp->value,
                            $op->classImplements
                        );
                    }
                    if (null !== $op->block1) {
                        $this->compileClass($op->block1, $this->context->scope->classId);
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->inheritInterfacePropertySetVisibility(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->propagateInterfaceConstantsToImplementors($nameOp->value);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'trait', $nameOp->value)) {
                        break;
                    }
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->type->object->setClassSourceLocation(
                        $this->context->scope->classId,
                        $op->sourceLocation
                    );
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->context->type->object->markTraitClass($this->context->scope->className);
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    if (null !== $this->context->runtime->vmContext) {
                        $lcname = strtolower($nameOp->value);
                        if (!isset($this->context->runtime->vmContext->classes[$lcname])) {
                            $traitEntry = new \PHPCompiler\VM\ClassEntry($nameOp->value);
                            $traitEntry->isTrait = true;
                            $this->context->runtime->vmContext->classes[$lcname] = $traitEntry;
                        }
                    }
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_ENUM:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->context->type->object->isRegisteredEnumLc(strtolower($nameOp->value))) {
                        break;
                    }
                    $this->jitCompileDeclareEnum($block, $op);
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $nameOp = $this->jitResolveClassLikeDeclareNameOperand($block, $op);
                    if (null === $nameOp) {
                        break;
                    }
                    if ($this->emitDuplicateClassLikeDeclareFatalIfNeeded($op, $block, 'class', $nameOp->value)) {
                        break;
                    }
                    // php-cfg may emit DECLARE_CLASS before DECLARE_ENUM in the same
                    // script block; compile enums first so class-const `E::X` can
                    // attach the singleton. Must run before pushScope so Enum::from
                    // lowering does not leak into the enclosing class function (#31967).
                    $this->jitCompilePendingEnumsInBlock($block);
                    $declareParentLc = null;
                    if (null !== $op->arg2) {
                        $earlyParent = $block->getOperand($op->arg2);
                        if ($earlyParent instanceof Operand\Literal && is_string($earlyParent->value)) {
                            $declareParentLc = strtolower(ltrim($earlyParent->value, '\\'));
                        }
                    }
                    if ([] !== $op->classImplements) {
                        \PHPCompiler\JIT\ImplementsHierarchyJitGuard::emitBeforeDeclare(
                            $this->context,
                            $nameOp->value,
                            $op->classImplements,
                            $block->scriptPath(),
                            $op->sourceLocation,
                            $declareParentLc,
                            false
                        );
                    }
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->type->object->setClassSourceLocation(
                        $this->context->scope->classId,
                        $op->sourceLocation
                    );
                    $this->context->scope->className = strtolower($nameOp->value);
                    if ($op->classIsAbstract) {
                        $this->context->type->object->markAbstractClass($nameOp->value);
                    }
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $packedFlags = $block->constants[$op->arg3]->toInt();
                        $this->context->scope->classIsReadonly = \PHPCompiler\VM\ClassFlags::isReadonly($packedFlags);
                        $this->context->type->object->setClassReadonly(
                            $this->context->scope->classId,
                            $this->context->scope->classIsReadonly
                        );
                        // Thin AOT isFinal name table (#34043) — ZEND_ACC_FINAL from packed flags.
                        if (\PHPCompiler\VM\ClassFlags::isFinal($packedFlags)) {
                            $this->context->type->object->markFinalClass($nameOp->value);
                        }
                    } else {
                        $this->context->scope->classIsReadonly = false;
                    }
                    $parentOp = null;
                    if (null !== $op->arg2) {
                        $parentOp = $block->getOperand($op->arg2);
                        assert($parentOp instanceof Operand\Literal);
                        $this->context->type->object->setClassParentName($nameOp->value, $parentOp->value);
                        // Before compileClass: user subclass methods / nested `new` need parent
                        // thin-AOT slots (`__spl_ht`, …) already on the child (#27565).
                        $this->context->type->object->inheritParentInstanceProperties(
                            $this->context->scope->classId,
                            strtolower(ltrim($parentOp->value, '\\'))
                        );
                    }
                    if ([] !== $op->attributeNames || [] !== $op->attributeEntries) {
                        $attrNames = [];
                        foreach ($op->attributeNames as $n) {
                            $attrNames[] = ltrim($n, '\\');
                        }
                        if (AttributeNames::hasAllowDynamicProperties($attrNames)) {
                            $this->context->type->object->setClassAllowsDynamicProperties(
                                $this->context->scope->classId,
                                true
                            );
                        }
                        AttributeRegistry::emitRegisterClass(
                            $this->context,
                            strtolower(ltrim($nameOp->value, '\\')),
                            [] !== $op->attributeEntries ? $op->attributeEntries : $attrNames
                        );
                    }
                    if (AttributeClassRegistry::isRegisteredAttributeClass($op->attributeEntries)) {
                        $this->context->type->object->markAttributeClass($nameOp->value);
                    }
                    // Zend evaluates class-const expressions after implements are attached
                    // (zend_inheritance.c). AOT const rematerialization needs the same order
                    // so `const Y = self::X` can see interface X (#31967).
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setClassInterfaces(
                            $nameOp->value,
                            $op->classImplements
                        );
                        $this->seedVmClassEntryInterfaces($nameOp->value, $op->classImplements);
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    if ($parentOp instanceof Operand\Literal) {
                        $this->context->type->object->inheritReadonlyFromParent(
                            $this->context->scope->classId,
                            $parentOp->value
                        );
                        $this->context->type->object->inheritMethodVisibilityFromParent(
                            $this->context->scope->classId,
                            $this->context->scope->className
                        );
                        $this->context->type->object->inheritParentStaticProperties(
                            $this->context->scope->classId,
                            strtolower(ltrim($parentOp->value, '\\'))
                        );
                    }
                    if ([] !== $op->classImplements) {
                        $this->context->type->object->setClassInterfaces(
                            $nameOp->value,
                            $op->classImplements
                        );
                    }
                    $this->context->type->object->inheritInterfaceConstants(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    $this->context->type->object->inheritInterfacePropertySetVisibility(
                        $this->context->scope->classId,
                        $nameOp->value
                    );
                    // Concrete subclass of AbstractLogger etc.: lower deferred LoggerTrait bodies (#36382).
                    if (!$op->classIsAbstract) {
                        $this->flushDeferredAbstractTraitMethodBodiesForConcrete($nameOp->value);
                    }
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_NEW:
                    $this->compileNewOp($block, $op);
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    // Nested `$obj->m()` while a TYPE_NEW construct is pending — same save
                    // as FUNCCALL_INIT / STATICCALL_INIT (#36382 / #27242).
                    $this->saveJitPendingOutboundCall();
                    $receiverOp = $block->getOperand($op->arg1);
                    $nameOp = $block->getOperand($op->arg2);
                    // `$obj->$m()` — name may be a Temporary; VM uses scope[arg2]->toString()
                    // (#34084). Fold compile-time string like FUNCCALL_INIT (#1997); #8407 was
                    // variable *receiver*, not variable *name*.
                    $nameSlot = $op->arg2;
                    if (!$nameOp instanceof Operand\Literal && isset($block->constants[$nameSlot])) {
                        $nameOp = new Operand\Literal($block->constants[$nameSlot]->toString());
                    }
                    $methodName = null;
                    $nameVar = null;
                    if ($nameOp instanceof Operand\Literal) {
                        $methodName = is_string($nameOp->value) ? $nameOp->value : (string) $nameOp->value;
                    } else {
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $slot = $block->slotForOperand($nameOp);
                        if (null !== $slot) {
                            $this->foldCompileTimeStringFromSlot($block, $slot, $nameVar);
                        }
                        if (null !== $nameVar->compileTimeString) {
                            $methodName = $nameVar->compileTimeString;
                        }
                    }
                    if (null === $methodName || '' === $methodName) {
                        // Runtime method name: `$this->$methodName()` after concat / HT fetch
                        // (Parsedown blockContinue / element handler — #36380). Peer of
                        // Class::$m() via RuntimeVariableStaticMethodCall (#34937).
                        if (null === $nameVar) {
                            $nameVar = $this->context->getVariableFromOp($nameOp);
                        }
                        $receiverVar = $this->context->getVariableFromOp($receiverOp);
                        $declaredLc = strtolower(ltrim((string) (
                            $receiverVar->classUserType
                            ?? $this->typedPropertyClassConstraintUserType($receiverVar)
                            ?? $receiverOp->type?->userType
                            ?? $this->context->scope->className
                            ?? ''
                        ), '\\'));
                        $candidates = $this->buildRuntimeInstanceMethodCandidatesByMethodName($declaredLc);
                        if ([] === $candidates) {
                            throw new \LogicException(
                                'Instance method call name must be a compile-time string or a typed receiver with known methods (dynamic $obj->$name(); #34084 / #36380)'
                            );
                        }
                        $this->context->scope->toCall = new \PHPCompiler\JIT\Call\RuntimeVariableStaticMethodCall(
                            $nameVar,
                            $candidates
                        );
                        $this->context->scope->args = [$receiverVar];
                        $this->context->scope->callArgsIncludeReceiver = true;
                        $this->context->scope->argOperands = [$receiverOp];
                        break;
                    }
                    $this->initJitMethodCall($block, $receiverOp, $methodName, $op->objectCallInvoke);
                    // initJitMethodCall seeds args=[receiver] but not argOperands. ARG_SEND only
                    // appends user-arg operands — without this prefix, promoteCompileTimeStringOnCallArgs
                    // pairs each arg with the *next* operand and shifts compileTimeString (#35234).
                    if (
                        1 === \count($this->context->scope->args)
                        && ($this->context->scope->args[0] ?? null) instanceof Variable
                    ) {
                        $this->context->scope->argOperands = [$receiverOp];
                    }
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                case OpCode::TYPE_PROPERTY_FETCH_WRITE:
                    $this->compilePropertyFetchOp($block, $op, $i, $func, $basicBlock);
                    break;
                case OpCode::TYPE_FROM_CALLABLE:
                    $fromCallableCont = $this->compileFromCallableOp($block, $op, $origBasicBlock);
                    if (null !== $fromCallableCont) {
                        return $fromCallableCont;
                    }
                    break;
                case OpCode::TYPE_BEGIN_SILENCE:
                    \PHPCompiler\JIT\ErrorSilenceHelper::beginSilence($this->context);
                    break;
                case OpCode::TYPE_END_SILENCE:
                    \PHPCompiler\JIT\ErrorSilenceHelper::endSilence($this->context);
                    break;
                default:
                    throw new \LogicException("Unknown JIT opcode: ". \PHPCompiler\opcode_type_name($op->type));
            }
        }

        $hasExplicitReturn = false;
        foreach ($block->opCodes as $scanOp) {
            if (OpCode::TYPE_RETURN === $scanOp->type || OpCode::TYPE_RETURN_VOID === $scanOp->type) {
                $hasExplicitReturn = true;
                break;
            }
        }
        $tail = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context);
        if (
            0 === $this->context->inlineIncludeDepth
            && $this->isVoidLlvmFunction($func)
            && !$block->syntheticCfgBranch
            && null !== $block->func
            && [] !== $block->opCodes
            && !$hasExplicitReturn
            && null !== $tail
            && null === $tail->getTerminator()
        ) {
            $builder->positionAtEnd($tail);
            $this->context->freeDeadVariables($func, $tail, $block);
            // Orphan auto-link emptied json_encode HT overlays (#31101); binaryOp uses
            // ensureOpenInsertBlockReplacingVoidReturn for value-box === reachability.
            $this->context->builder->returnVoid();
        }

        return \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context) ?? $basicBlock;
    }

    /**
     * get_class($obj) keeps a compile-time class name when $obj is a known new/anonymous
     * instance — needed for stream_wrapper_register(..., get_class(new class {})) (#36382 Nyholm).
     *
     * @param array<int, \PHPCompiler\JIT\Variable> $callArgs
     */
    private function propagateGetClassCompileTimeString(
        Operand $result,
        mixed $toCall,
        array $callArgs
    ): void {
        if (!($toCall instanceof CoreFunc\Internal)) {
            return;
        }
        if ('get_class' !== strtolower($toCall->getName())) {
            return;
        }
        $obj = $callArgs[0] ?? null;
        if (!$obj instanceof Variable) {
            return;
        }
        $name = $obj->classUserType;
        if (null === $name || '' === $name) {
            if (Variable::TYPE_OBJECT === $obj->type && null !== $obj->compileTimeString && '' !== $obj->compileTimeString) {
                $name = $obj->compileTimeString;
            } else {
                return;
            }
        }
        if (!$this->context->hasVariableOp($result)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($result);
        $resultVar->compileTimeString = $name;
    }
}
