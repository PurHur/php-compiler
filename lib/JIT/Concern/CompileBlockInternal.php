<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\JIT\Builtin\AttributeRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IssetHelper;
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
                    // Resume fn has no PHP argv — formals live in heap frame filled at
                    // generator create (emitCreateFromCall) (#35142).
                    if ($this->context->compilingGeneratorResume) {
                        break;
                    }
                    $recvSlot = $op->arg2 + $thisParamOffset;
                    $isVariadicSlot = null !== $block->variadicParamIndex
                        && $block->variadicParamIndex === (int) $op->arg2;
                    if ($isVariadicSlot) {
                        $packed = isset($args[$recvSlot])
                            ? $args[$recvSlot]
                            : \PHPCompiler\JIT\HashTableHelper::emptyVariable($this->context);
                        // Keep TYPE_HASHTABLE — do not box here. Boxing made foreach over
                        // `...$args` / `&...$args` emit parentless `__value__readObject` IR
                        // (#34684) and broke by-ref element write-back (#27407). Array builtins
                        // that need a value-box coerce at the call site (ArraySumLlvm / #24167).
                        $recvOp = $block->getOperand($op->arg1);
                        // Prologue already assignOperand'd the packed HT onto Param.result. A
                        // second assignOperand (same slot or Temporary) free()s that HT
                        // (delref) before re-storing — dangling pack; $v[0]/implode/foreach
                        // SEGV (e08_spread #24226, k09 #24167). Same skip as typed `array`
                        // formals below (#36386 / #36397).
                        if (
                            Variable::TYPE_HASHTABLE === $packed->type
                            && $this->context->hasVariableOp($recvOp)
                            && Variable::TYPE_HASHTABLE === $this->context->getVariableFromOp($recvOp)->type
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            if (isset($block->paramByRef[(int) $op->arg2])) {
                                $this->context->getVariableFromOp($recvOp)->borrowedHashtable = true;
                            }
                            break;
                        }
                        // Same-slot Temporary distinct from Param.result — alias the prologue
                        // HT instead of assignOperand (which would delref the live pack).
                        if (
                            !$this->context->hasVariableOp($recvOp)
                            && null !== $block->func
                            && isset($block->func->params[(int) $op->arg2])
                        ) {
                            $paramResult = $block->func->params[(int) $op->arg2]->result;
                            if (
                                $this->context->hasVariableOp($paramResult)
                                && Variable::TYPE_HASHTABLE === $this->context->getVariableFromOp($paramResult)->type
                            ) {
                                $bound = $this->context->getVariableFromOp($paramResult);
                                $this->context->setVariableOp($recvOp, $bound);
                                \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                    $this->context,
                                    $recvOp,
                                    $bound
                                );
                                if (isset($block->paramByRef[(int) $op->arg2])) {
                                    $bound->borrowedHashtable = true;
                                }
                                break;
                            }
                        }
                        $this->assignOperand($recvOp, $packed, true);
                        // `&...$args`: dim writes must hit the same HT syncByRefVariadicCallers
                        // reads — FETCH_DIM_W COW would leave the pack stale (#34790 / #34508).
                        if (
                            isset($block->paramByRef[(int) $op->arg2])
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            $this->context->getVariableFromOp($recvOp)->borrowedHashtable = true;
                        }
                        break;
                    }
                    if (!isset($args[$recvSlot])) {
                        throw new \LogicException('Missing required argument ' . $op->arg2);
                    }
                    if (isset($block->paramByRef[(int) $op->arg2])) {
                        $recvOp = $block->getOperand($op->arg1);
                        // getOperand may return a same-slot Temporary distinct from the CFG
                        // Param.result Variable already bound in the prologue (#24162).
                        if (!$this->context->hasVariableOp($recvOp)) {
                            $this->context->getVariableFromOp($recvOp);
                        }
                        $this->bindJitParamByReference(
                            $block,
                            $recvOp,
                            $args[$recvSlot]
                        );
                    } else {
                        // Prologue already bind+separate string formals via the
                        // LLVM function signature (AOT) or prepareNestedJitCalleeParamArgument
                        // (NestedJIT). Re-assigning the raw LLVM formal here empties heap
                        // __string__* content (length ok, bytes gone / UAF) — #24137, #24723.
                        // Skip ARG_RECV overwrite when the recv op is already bound — but still
                        // markAssigned so undefined-variable guards stay quiet (#31101 MiniWebApp
                        // `$route` warnings on stderr after string formal prologue bind).
                        $recvOp = $block->getOperand($op->arg1);
                        if (
                            Variable::TYPE_STRING === $args[$recvSlot]->type
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            break;
                        }
                        // Prologue already assignOperand'd typed `array` formals onto a
                        // KIND_VARIABLE slot. A second assignOperand free()s that HT
                        // (delref) before re-storing — with caller rc=1 that frees the
                        // table under the callee and the caller's value-box (#36386).
                        // Same shape as the string skip above (#24137).
                        if (
                            Variable::TYPE_HASHTABLE === $args[$recvSlot]->type
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            break;
                        }
                        // Typed `object` / class formals: prologue already stored the
                        // `__object__*` into the local. A second assignOperand runs
                        // obj_mirror delref on the live object then re-stores — that
                        // drops the sole caller ref so `take($this)` leaves typed props
                        // uninitialized (#36382 AppFactory Runner($this) / Holder($this)).
                        // Peer of string (#24137) and hashtable (#36386) ARG_RECV skips.
                        if (
                            Variable::TYPE_OBJECT === $args[$recvSlot]->type
                            && $this->context->hasVariableOp($recvOp)
                        ) {
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $recvOp,
                                $this->context->getVariableFromOp($recvOp)
                            );
                            break;
                        }
                        if ($this->storeJitCalleeValueStructFormal(
                            $recvOp,
                            $this->prepareNestedJitCalleeParamArgument($args[$recvSlot])
                        )) {
                            break;
                        }
                        $this->assignOperand(
                            $recvOp,
                            $this->prepareNestedJitCalleeParamArgument($args[$recvSlot])
                        );
                    }
                    break;
                case OpCode::TYPE_ASSIGN:
                    $this->compileAssignOp($block, $op, $i, $func, $basicBlock, $thisParamOffset, ...$args);
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $this->compileAssignRefOp($block, $op);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $block->constants[$op->arg2]->toString();
                    $globalVar = $this->ensureJitGlobal($globalName);
                    $this->context->jitImportedGlobalNames[$globalName] = true;
                    $this->context->bindVariableByName($globalName, $globalVar);
                    $destOp = $block->getOperand($op->arg1);
                    $this->context->setVariableOp($destOp, $globalVar);
                    $globalSlot = $block->slotForOperand($destOp);
                    if (null !== $globalSlot) {
                        foreach ($block->scopedOperands() as $scopeOp) {
                            if ($block->slotForOperand($scopeOp) === $globalSlot) {
                                $this->context->setVariableOp($scopeOp, $globalVar);
                            }
                        }
                    }
                    break;
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $storageKey = $block->constants[$op->arg2]->toString();
                    $destOp = $block->getOperand($op->arg1);
                    if (!$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    $staticVar = $this->ensureJitFunctionStatic($storageKey);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $staticDefaultVm = $block->constants[$op->arg3];
                        // php-cfg often mistypes function-static defaults (string[] as string,
                        // or leaves string defaults CFG-unknown). Retype so FETCH_DIM_W picks
                        // HT vs ValueBoxDimWrite correctly (#32800 / #32806 / #32814 / #32830).
                        if (\PHPCompiler\VM\Variable::TYPE_ARRAY === $staticDefaultVm->type) {
                            $this->retypeFunctionStaticOperand($block, $destOp, new Type(Type::TYPE_ARRAY));
                        } elseif (\PHPCompiler\VM\Variable::TYPE_STRING === $staticDefaultVm->type) {
                            $this->retypeFunctionStaticOperand($block, $destOp, Type::string());
                        }
                        \PHPCompiler\JIT\FunctionStaticHelper::emitLazyInit(
                            $this->context,
                            $storageKey,
                            $staticVar,
                            $this->jitVariableFromVmConstant($staticDefaultVm)
                        );
                    }
                    $this->context->setVariableOp($destOp, $staticVar);
                    // Function-static CVs are always defined once DECLARE runs (lazy or runtime
                    // init completed on this path) — quiet ZEND_CHECK_UNDEFINED_VAR (#35665).
                    \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned($this->context, $destOp, $staticVar);
                    $staticName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                    if (null !== $staticName && '' !== $staticName) {
                        $this->context->bindVariableByName($staticName, $staticVar);
                    }
                    $staticSlot = $block->slotForOperand($destOp);
                    if (null !== $staticSlot) {
                        foreach ($block->scopedOperands() as $scopeOp) {
                            if ($block->slotForOperand($scopeOp) === $staticSlot) {
                                $this->context->setVariableOp($scopeOp, $staticVar);
                            }
                        }
                    }
                    break;
                case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $jumpKey = $block->constants[$op->arg2]->toString();
                    $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    $skipEntry = $this->jitBranchEntryBlock($op->block1, $func);
                    $initPathBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'fn_static_init_path');
                    $builder->positionAtEnd($branchBlock);
                    $builder->branchIf(
                        \PHPCompiler\JIT\FunctionStaticHelper::isInitializedCondition($this->context, $jumpKey),
                        $skipEntry,
                        $initPathBb
                    );
                    $builder->positionAtEnd($initPathBb);
                    break;
                case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    if (null === $op->arg3) {
                        throw new \LogicException('Function static init store requires a value slot');
                    }
                    $storeKey = $block->constants[$op->arg2]->toString();
                    $storeVar = $this->ensureJitFunctionStatic($storeKey);
                    $initValue = $this->variableFromBlockSlot($block, (int) $op->arg3);
                    \PHPCompiler\JIT\FunctionStaticHelper::emitRuntimeInitStore(
                        $this->context,
                        $storeKey,
                        $storeVar,
                        $initValue
                    );
                    break;
                case OpCode::TYPE_VAR_FETCH:
                    $destOp = $block->getOperand($op->arg1);
                    if (!$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    $nameSlot = (int) $op->arg2;
                    foreach ($block->scopedOperands() as $slotOp) {
                        if ($block->slotForOperand($slotOp) === $nameSlot && !$this->context->hasVariableOp($slotOp)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $slotOp);
                        }
                    }
                    $nameVar = $this->variableFromBlockSlot($block, $nameSlot);
                    $this->foldVarFetchNameFromAssign($block, $nameSlot, $nameVar);
                    $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
                    $target = \PHPCompiler\JIT\VarFetchHelper::resolveTarget($this->context, $block, $nameVar, $forWrite);
                    if ($forWrite) {
                        $this->context->setVariableOp($destOp, $target);
                    } else {
                        $this->assignOperand($destOp, $target, true);
                    }
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
                    if (null !== $op->block1) {
                        $branchBlock = $builder->getInsertBlock();
                        $builder->positionAtEnd($branchBlock);
                        $array = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                        if (!$this->context->listUnpackMergeLlvmBlocks->contains($op->block1)) {
                            ++self::$blockNumber;
                            $mergeBody = $func->appendBasicBlock('block_'.self::$blockNumber);
                            $this->context->listUnpackMergeLlvmBlocks[$op->block1] = $mergeBody;
                        } else {
                            $mergeBody = $this->context->listUnpackMergeLlvmBlocks[$op->block1];
                        }
                        $this->context->listUnpackAssignRootBlock = $block;
                        $this->context->listUnpackSkipAssignPath = \PHPCompiler\JIT\ListUnpackHelper::emitGuardedListUnpackCheck(
                            $this->context,
                            $array,
                            $branchBlock,
                            $mergeBody,
                            $block->getOperand($op->arg2),
                            $op->listUnpackHasByRef,
                            $this
                        );
                        break;
                    }
                    \PHPCompiler\JIT\ListUnpackHelper::emitCheck(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_LIST_SPREAD_ASSIGN:
                    if (!CompilerVersion::supportsListDestructuringSpreadAssign()) {
                        throw new \Error('Spread operator is not supported in assignments');
                    }
                    if ($this->context->listUnpackSkipAssignPath) {
                        break;
                    }
                    if (!isset($block->constants[$op->arg3])) {
                        throw new \LogicException('list spread assign requires compile-time offset');
                    }
                    $spreadDestOp = $block->getOperand($op->arg1);
                    $spreadSrc = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $spreadI64 = $this->context->getTypeFromString('int64');
                    $spreadI1 = $this->context->getTypeFromString('int1');
                    $spreadOffset = $spreadI64->constInt($block->constants[$op->arg3]->toInt(), false);
                    if ([] !== $op->listSpreadExcludedKeys) {
                        $spreadTailHt = \PHPCompiler\JIT\Builtin\ListSpreadTailRuntime::copyTail(
                            $this->context,
                            $spreadSrc,
                            $spreadOffset,
                            $op->listSpreadExcludedKeys
                        );
                    } else {
                        if (!\PHPCompiler\JIT\ListUnpackHelper::isDefinitelyNonArrayAtCompileTime($this->context, $spreadSrc)) {
                            \PHPCompiler\JIT\ListUnpackHelper::emitIsListBranchOrFail($this->context, $spreadSrc);
                        }
                        $spreadTailHt = \PHPCompiler\JIT\Builtin\ArraySliceRuntime::slice(
                            $this->context,
                            $spreadSrc,
                            $spreadOffset,
                            $spreadI1->constInt(0, false),
                            $spreadI64->constInt(0, false)
                        );
                    }
                    $spreadDestVar = $this->context->getVariableFromOp($spreadDestOp);
                    if (0 !== ($spreadDestVar->type & Variable::IS_NATIVE_ARRAY)) {
                        $spreadBox = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                        $this->context->setVariableOp(
                            $spreadDestOp,
                            new Variable(
                                $this->context,
                                Variable::TYPE_VALUE,
                                Variable::KIND_VARIABLE,
                                $spreadBox
                            )
                        );
                    }
                    $spreadTailVar = new Variable(
                        $this->context,
                        Variable::TYPE_HASHTABLE,
                        Variable::KIND_VALUE,
                        $spreadTailHt
                    );
                    $this->assignOperand($spreadDestOp, $spreadTailVar);
                    break;
                case OpCode::TYPE_TYPE_ASSERT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_EMPTY:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $emptyResult = \PHPCompiler\JIT\EmptyObjectPropertyHelper::compileEmptyFromValue(
                        $this->context,
                        $from
                    );
                    // Force: php-cfg leaves FuncCall args on dead temps while ARG_SEND is
                    // remapped to this empty/isset result; empty usages would skip the store
                    // and ARG_SEND would materialize a null box (AOT var_export NULL, #28622).
                    $this->assignOperandValue(
                        $block->getOperand($op->arg1),
                        $emptyResult,
                        true
                    );
                    break;
                case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = $block->getOperand($op->arg3);
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = $this->context->getVariableFromOp($dimOp);
                    $emptyResult = \PHPCompiler\JIT\EmptyObjectPropertyHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $emptyResult, true);
                    break;
                case OpCode::TYPE_EMPTY_STATIC_PROPERTY:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    $emptyResult = \PHPCompiler\JIT\EmptyStaticPropertyHelper::compile(
                        $this->context,
                        $classOp,
                        $nameOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $emptyResult, true);
                    break;
                case OpCode::TYPE_EMPTY_DIMENSION:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = $block->getOperand($op->arg3);
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = $this->context->getVariableFromOp($dimOp);
                    $emptyResult = \PHPCompiler\JIT\EmptyDimensionHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $emptyResult, true);
                    break;
                case OpCode::TYPE_EVAL:
                    \PHPCompiler\JIT\EvalHelper::compile($this, $func, $block, $op);
                    break;
                case OpCode::TYPE_ISSET:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
                    if ($op->issetOnStaticProperty) {
                        $issetResult = IssetHelper::compileStaticProperty(
                            $this->context,
                            $containerOp,
                            $dimOp
                        );
                        $this->assignOperandValue($block->getOperand($op->arg1), $issetResult, true);
                        break;
                    }
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = null !== $dimOp ? $this->context->getVariableFromOp($dimOp) : null;
                    $issetResult = IssetHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp,
                        $op->issetOnProperty
                    );
                    // Force store: see TYPE_EMPTY above (#28622 / peer #11498).
                    $this->assignOperandValue($block->getOperand($op->arg1), $issetResult, true);
                    break;
                case OpCode::TYPE_ITER_RESET:
                    $arrayOp = $block->getOperand($op->arg1);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    // Zend FE_RESET / CV fetch: Undefined variable E_WARNING before type check (#26148).
                    \PHPCompiler\JIT\UndefinedVariableHelper::guardBeforeRuntimeRead($this->context, $arrayOp, $array);
                    \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                    if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        \PHPCompiler\JIT\GeneratorHelper::compileIterReset($this->context, $array);
                        break;
                    }
                    \PHPCompiler\JIT\IteratorHelper::compileReset(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp, $array)
                    );
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                    if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        $valid = \PHPCompiler\JIT\GeneratorHelper::compileIterValid($this->context, $array);
                        $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                        break;
                    }
                    $valid = \PHPCompiler\JIT\IteratorHelper::compileValid(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp, $array)
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                    if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        $key = \PHPCompiler\JIT\GeneratorHelper::compileIterKey($this->context, $array);
                        $this->assignOperand($block->getOperand($op->arg1), $key);
                        break;
                    }
                    $key = \PHPCompiler\JIT\IteratorHelper::compileKey(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp, $array)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $key);
                    $arraySlot = $block->slotForOperand($arrayOp);
                    if (null !== $arraySlot) {
                        $this->context->foreachPendingKeyByArraySlot[$arraySlot] = $key;
                    }
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    \PHPCompiler\JIT\GeneratorHelper::hydrateGeneratorMetadata($this->context, $array);
                    if (\PHPCompiler\JIT\GeneratorHelper::isGeneratorVariable($array)) {
                        if ($op->arg3) {
                            $value = \PHPCompiler\JIT\GeneratorHelper::compileIterValueByRef($this->context, $array, $this);
                            $this->context->setVariableOp($block->getOperand($op->arg1), $value);
                            break;
                        }
                        $value = \PHPCompiler\JIT\GeneratorHelper::compileIterValue($this->context, $array);
                        $this->assignOperand($block->getOperand($op->arg1), $value);
                        break;
                    }
                    if ($op->arg3) {
                        $destOp = $block->getOperand($op->arg1);
                        $destName = \PHPCompiler\JIT\OperandName::resolve($destOp);
                        if (null !== $destName) {
                            $this->context->foreachByRefLocalNames[
                                $this->context->resolveRefAliasName($destName)
                            ] = true;
                        }
                        $value = \PHPCompiler\JIT\IteratorHelper::compileValueByRef(
                            $this->context,
                            $array,
                            self::foreachContainerUserType($arrayOp, $array),
                            $this
                        );
                        $this->context->setVariableOp($destOp, $value);
                        if (null !== $destName) {
                            $this->context->bindVariableByName($destName, $value);
                            \PHPCompiler\JIT\UndefinedVariableHelper::markAssigned(
                                $this->context,
                                $destOp,
                                $value
                            );
                        }
                        break;
                    }
                    $value = \PHPCompiler\JIT\IteratorHelper::compileValue(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp, $array)
                    );
                    $destOp = $block->getOperand($op->arg1);
                    $this->assignOperand($destOp, $value);
                    $this->reattachForeachIterClosureInvokeMetadata($block, $arrayOp, $destOp, $value);
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                    if (OpCode::SCRIPT_MAGIC_HALT_OFFSET === (int) $op->arg3) {
                        $offset = $block->haltCompilerOffset;
                        if (null === $offset) {
                            throw new \LogicException('Undefined constant "__COMPILER_HALT_OFFSET__"');
                        }
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            \PHPCompiler\JIT\Variable::fromConstantInt($this->context, $offset)
                        );
                    } elseif (OpCode::SCRIPT_MAGIC_LINE === (int) $op->arg3) {
                        $line = null !== $op->arg2 ? (int) $op->arg2 : 1;
                        if ($line < 1) {
                            $line = 1;
                        }
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            \PHPCompiler\JIT\Variable::fromConstantInt($this->context, $line)
                        );
                    } else {
                        $magicStr = \PHPCompiler\JIT\ScriptMagic::stringForBlock($block, (int) $op->arg3);
                        $lit = new Operand\Literal($magicStr);
                        $lit->type = \PHPTypes\Type::string();
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            \PHPCompiler\JIT\Variable::fromLiteral($this->context, $lit)
                        );
                    }
                    break;
                case OpCode::TYPE_INCLUDE:
                    if ($this->context->inlineIncludeDepth > 0) {
                        \PHPCompiler\JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    \PHPCompiler\JIT\IncludeHelper::compileLiteral(
                        $this,
                        $func,
                        $block,
                        $op,
                        null !== $op->arg2 ? $block->getOperand($op->arg2) : null
                    );
                    break;
                case OpCode::TYPE_CLONE:
                    \PHPCompiler\JIT\CloneOperandHelper::compile($this, $this->context, $block, $op);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if ($from->type === Variable::TYPE_NATIVE_BOOL) {
                        $value = $this->context->helper->loadValue($from);
                    } else {
                        $value = $this->context->castToBool($this->context->helper->loadValue($from));
                    }
                    $__right = $value->typeOf()->constInt(1, false);
                    $result = $this->context->builder->bitwiseXor($value, $__right);
                    // Force: php-cfg leaves `var_dump(!$o)` on a dead temp while ARG_SEND is
                    // remapped to the not-result — empty usages skip the store (#32471 / #32293).
                    $this->assignOperandValue($block->getOperand($op->arg1), $result, true);
                    break;
                case OpCode::TYPE_CONCAT:
                    $this->compileConcatOp($block, $op, $i, $func);
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $value = null;
                    if (!is_null($op->arg3)) {
                        // try NS constant fetch
                        $value = $this->context->constantFetch($block->getOperand($op->arg3));
                    }
                    if (is_null($value)) {
                        $value = $this->context->constantFetch($block->getOperand($op->arg2));
                    }
                    if (is_null($value)) {
                        $name = $block->getOperand($op->arg2);
                        $label = $name instanceof Operand\Literal ? (string) $name->value : get_class($name);
                        if (null !== $op->arg3) {
                            $ns = $block->getOperand($op->arg3);
                            if ($ns instanceof Operand\Literal) {
                                $label = (string) $ns->value.'\\'.$label;
                            }
                        }
                        $bundleConst = $this->jitFoldPhpCompilerBundleConstant($label);
                        if (null !== $bundleConst) {
                            $this->assignOperand($block->getOperand($op->arg1), $bundleConst);
                            break;
                        }
                        throw new \RuntimeException('Undefined constant "'.$label.'"');
                    }
                    // CONST_DEPRECATED globals (E_STRICT, ASSERT_*, …) — Zend zend_constants.c (#29229).
                    $fetchNameOp = null !== $op->arg3
                        ? $block->getOperand($op->arg3)
                        : $block->getOperand($op->arg2);
                    if ($fetchNameOp instanceof Operand\Literal && \is_string($fetchNameOp->value)) {
                        $constFetchName = $fetchNameOp->value;
                        $depMeta = $this->context->runtime->vmContext->globalConstDeprecated[strtolower($constFetchName)] ?? null;
                        if (null !== $depMeta) {
                            \PHPCompiler\JIT\DeprecatedCallGuard::emitGlobalConstFetch(
                                $this->context,
                                $depMeta,
                                $constFetchName
                            );
                        }
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $this->compileClassConstFetchOp($block, $op);
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    $expr = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $unionEncoded = $op->instanceofUnionTypes;
                    if (null !== $unionEncoded && '' !== $unionEncoded) {
                        $types = array_values(array_filter(explode('|', $unionEncoded), static fn (string $t): bool => '' !== $t));
                        $result = $this->context->type->object->emitInstanceOfUnion($expr, $types);
                        $this->assignOperand($block->getOperand($op->arg1), $result);
                        break;
                    }
                    $keyword = $op->instanceofScopeKeyword;
                    if (null !== $keyword && '' !== $keyword) {
                        // `static` late-binds to $this / called class (Zend ZEND_INSTANCEOF).
                        // AOT must not bake the trait/declaring name — that makes trait
                        // `instanceof static` always false (#31746).
                        if (
                            'static' === $keyword
                            && \PHPCompiler\JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context)
                        ) {
                            $classIdVal = \PHPCompiler\JIT\ClassConstFetchHelper::emitStaticKeywordClassIdForPseudoConst(
                                $this->context->type->object,
                                $block
                            );
                            $result = \PHPCompiler\JIT\InstanceOfHelper::emitWithRuntimeClassId(
                                $this->context,
                                $expr,
                                $classIdVal
                            );
                            $this->assignOperand($block->getOperand($op->arg1), $result);
                            break;
                        }
                        // Trait flatten compiles this block with traitComposingClassName set (#31729).
                        $resolved = $this->resolveJitStaticScopeClass(
                            $block,
                            new Operand\Literal($keyword)
                        );
                        $result = $this->context->type->object->emitInstanceOf($expr, $resolved);
                        $this->assignOperand($block->getOperand($op->arg1), $result);
                        break;
                    }
                    $result = \PHPCompiler\JIT\InstanceOfHelper::emit(
                        $this->context,
                        $expr,
                        $block->getOperand($op->arg3)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_IN:
                    $needle = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $haystack = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $found = \PHPCompiler\JIT\InOperatorHelper::emitContains($this->context, $needle, $haystack);
                    $this->assignOperand($block->getOperand($op->arg1), $found);
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
                    if (null === $op->arg2) {
                        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType) {
                            \PHPCompiler\JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                        }
                        $i32 = $this->context->getTypeFromString('int32');
                        $this->context->builder->call(
                            $this->context->lookupFunction('exit'),
                            $i32->constInt(0, false)
                        );
                        break;
                    }
                    $exitArg = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $prevExitStrict = $this->context->callerStrictTypes;
                    $this->context->callerStrictTypes = $block->strictTypes;
                    try {
                        if (null !== $op->exitMessageSlot) {
                            $messageArg = $this->context->getVariableFromOp($block->getOperand($op->exitMessageSlot));
                            \PHPCompiler\JIT\Builtin\ScriptExit::emitWithMessage($this->context, $exitArg, $messageArg);
                            break;
                        }
                        \PHPCompiler\JIT\Builtin\ScriptExit::emit($this->context, $exitArg);
                    } finally {
                        $this->context->callerStrictTypes = $prevExitStrict;
                    }
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
                    if (null === $op->arg3) {
                        break;
                    }
                    if ($op->isIncDec && (OpCode::TYPE_PLUS === $op->type || OpCode::TYPE_MINUS === $op->type)) {
                        $this->maybeRefreshIncludeBindingsBeforeUse();
                        $left = $this->context->getVariableFromOp($this->binaryOpLeftOperand($block, $op));
                        $right = $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, 'inc/dec right'));
                        $resultOp = $this->operandAt($block, $op->arg1, 'inc/dec result');
                        $literal = \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($left) ?? \PHPCompiler\JIT\JitStringArg::compileTimeLiteral($right);
                        if (null !== $literal) {
                            $vm = new \PHPCompiler\VM\Variable();
                            $vm->string($literal);
                            if (OpCode::TYPE_PLUS === $op->type) {
                                // php-src increment_string(): empty / non-alnum → E_DEPRECATED (#29658).
                                $this->emitStringIncrementDeprecationsIfNeeded($literal);
                                $vm->applyIncrement();
                            } else {
                                // php-src decrement_function() string path (#29088, #29658).
                                $this->emitStringDecrementDeprecationsIfNeeded($literal);
                                $vm->applyDecrement();
                            }
                            $this->assignOperand($resultOp, $this->jitVariableFromVmConstant($vm), true);
                            break;
                        }
                    }
                    // fall through
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
                    if (null === $op->arg3) {
                        break;
                    }
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $binLeftOp = $this->binaryOpLeftOperand($block, $op);
                    $binRightOp = $this->operandAt($block, $op->arg3, \PHPCompiler\opcode_type_name($op->type).' right');
                    $binDestOp = $this->operandAt($block, $op->arg1, \PHPCompiler\opcode_type_name($op->type).' result');
                    $binLeft = $this->variableFromOpForRuntimeRead($binLeftOp);
                    if (
                        \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($binLeft, $this->context)
                        || (
                            $this->context->hasVariableOp($binDestOp)
                            && \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue(
                                $this->context->getVariableFromOp($binDestOp),
                                $this->context
                            )
                        )
                    ) {
                        // Zend: Cannot use assign-op operators with string offsets (#22897).
                        \PHPCompiler\JIT\StringOffsetHelper::emitAssignOpError($this->context);
                        break;
                    }
                    // FETCH_DIM_W assign-op ($a[i] += n): hydrate orphan box before the read (#32789).
                    if (null !== $binLeft->writableHt) {
                        \PHPCompiler\JIT\HashTableHelper::hydrateDimWriteLvalue($this->context, $binLeft);
                    }
                    $this->assignOperand(
                        $binDestOp,
                        $this->compileBinaryOp(
                            $op,
                            $binLeft,
                            $this->variableFromOpForRuntimeRead($binRightOp)
                        )
                    );
                    if (
                        OpCode::TYPE_IDENTICAL === $op->type
                        || OpCode::TYPE_NOT_IDENTICAL === $op->type
                    ) {
                        // Identical/not-identical only need the bool result. Release Temporary
                        // object boxes now (Zend statement-end) so WeakReference::get() temps do
                        // not outlive a following unset in the same CFG block (#27118).
                        $this->jitReleaseTempValueBoxAfterCompare($block, $binLeftOp);
                        $this->jitReleaseTempValueBoxAfterCompare($block, $binRightOp);
                        $this->jitReleasePendingWeakReferenceGetResult();
                    }
                    break;
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_LOGICAL_XOR:
                case OpCode::TYPE_SPACESHIP:
                    if (null === $op->arg3) {
                        break;
                    }
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $this->assignOperand(
                        $this->operandAt($block, $op->arg1, \PHPCompiler\opcode_type_name($op->type).' result'),
                        $this->compileBinaryOp(
                            $op,
                            $this->variableFromOpForRuntimeRead($this->binaryOpLeftOperand($block, $op)),
                            $this->variableFromOpForRuntimeRead($this->operandAt($block, $op->arg3, \PHPCompiler\opcode_type_name($op->type).' right'))
                        )
                    );
                    break;
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_BITWISE_NOT:
                case OpCode::TYPE_UNARY_PLUS:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        OpCode::TYPE_UNARY_PLUS === $op->type
                            ? \PHPCompiler\JIT\JitUnaryPlus::lower(
                                $this->context,
                                $op,
                                $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                            )
                            : (OpCode::TYPE_UNARY_MINUS === $op->type
                                ? \PHPCompiler\JIT\JitUnaryMinus::lower(
                                    $this->context,
                                    $op,
                                    $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                                )
                                : $this->context->helper->unaryOp(
                                    $op,
                                    $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                                ))
                    );
                    break;
                case OpCode::TYPE_CASE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $switchVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'switch value'));
                    $caseVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, 'switch case'));
                    $equalOp = new OpCode(OpCode::TYPE_EQUAL);
                    $matchVar = $this->context->helper->binaryOp($equalOp, $switchVar, $caseVar);
                    $match = $this->context->castToBool(
                        $this->context->helper->loadValue($matchVar)
                    );
                    $this->compileBlockInternal($func, $op->block1, null, null, 0, false, ...$args);
                    $caseEntry = $this->jitBranchEntryBlock($op->block1, $func);
                    $nextBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'switch_next_case');
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($match, $caseEntry, $nextBb);
                    $builder->positionAtEnd($nextBb);
                    break;
                case OpCode::TYPE_JUMP:
                    \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'jump_cont');
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $skippedListUnpackAssign = $this->context->listUnpackSkipAssignPath;
                    $this->context->listUnpackSkipAssignPath = false;
                    $mergeLlvm = null;
                    $allowRecompile = false;
                    if ($this->context->listUnpackMergeLlvmBlocks->contains($op->block1)) {
                        $mergeLlvm = $this->context->listUnpackMergeLlvmBlocks[$op->block1];
                        $allowRecompile = true;
                        $this->context->listUnpackMergeLlvmBlocks->detach($op->block1);
                        if ($skippedListUnpackAssign) {
                            $mergeKey = spl_object_id($op->block1);
                            $this->context->listUnpackMergeNullInitTargets[$mergeKey]
                                = $this->listUnpackAssignTargetsInBlock($block);
                        }
                    }
                    $this->context->listUnpackAssignCallerBlock = $block;
                    $this->compileBlockInternal($func, $op->block1, null, $mergeLlvm, 0, $allowRecompile, ...$args);
                    $this->context->listUnpackAssignCallerBlock = null;
                    $targetEntry = \PHPCompiler\JIT\TryCatchHelper::leaveBranchTarget(
                        $this,
                        $this->context,
                        $func,
                        $block,
                        $op->block1,
                        $args
                    );
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Use the merge block itself (not getInsertBlock — callee may be cached) (#846, #784).
                        $this->context->inlineIncludeExitBlock = $targetEntry;
                    }
                    $builder->positionAtEnd($branchBlock);
                    if (
                        $this->shouldFreeDeadVariablesBeforeBranch()
                        && !$this->mergeBlockInheritsCallerLocals($op->block1)
                    ) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branch($targetEntry);
                    return $origBasicBlock;
                case OpCode::TYPE_COALESCE:
                    // Match TYPE_NULLSAFE: NestedJIT CoalesceJitHelper / script-global
                    // init can clear insert; a parentless load of phpc_script_global_*
                    // fails module verify for `echo $undef ?? 'd'` (#32445).
                    $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context);
                    if (null === $branchBlock) {
                        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'coalesce_branch');
                        $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context) ?? $origBasicBlock;
                    }
                    $builder->positionAtEnd($branchBlock);
                    $coalesceResult = $block->getOperand($op->arg1);
                    $this->context->coalesceAssignTargets[$coalesceResult] = true;
                    // Pre-allocate one stack slot before branches so left/right/merge all write
                    // the same alloca — otherwise AOT ?? + === / == on the merge reads a bad
                    // temp and MiniWebApp AOT exits with empty stdout (#31101 / #26818).
                    $this->ensureCoalesceMergeStackSlot($coalesceResult);
                    \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'coalesce_after_slot');
                    $mergeSlot = $block->slotForOperand($coalesceResult);
                    if (null !== $mergeSlot) {
                        $this->context->coalesceMergeSlotOperands[$mergeSlot] = $coalesceResult;
                    }
                    $condition = \PHPCompiler\JIT\CoalesceHelper::isTakeLeftBranch(
                        $this,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    // Branch from the block that defined $condition (e.g. sg_sk_done after $_SERVER['key']).
                    // Repositioning to $branchBlock caused invalid LLVM when ?? left uses multi-block reads (#866).
                    $coalesceTestBlock = $builder->getInsertBlock();
                    // Seal the test BB before lowering arms (#32880). Compiling
                    // PROPERTY_FETCH_WRITE first left prop_value_done open after `new`;
                    // NestedJIT ensureOpenInsertBlock resumed there and planted a second br.
                    if (!$func instanceof PHPLLVM\Value\Function_) {
                        throw new \LogicException('TYPE_COALESCE expects an LLVM function');
                    }
                    self::$blockNumber++;
                    $leftEntry = $func->appendBasicBlock('coalesce_left_' . self::$blockNumber);
                    self::$blockNumber++;
                    $rightEntry = $func->appendBasicBlock('coalesce_right_' . self::$blockNumber);
                    $builder->positionAtEnd($coalesceTestBlock);
                    if (null !== $coalesceTestBlock->getTerminator()) {
                        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'coalesce_test_resume');
                        $coalesceTestBlock = $builder->getInsertBlock() ?? $coalesceTestBlock;
                        $builder->positionAtEnd($coalesceTestBlock);
                    }
                    // Do not free php-cfg "dead" operands here; ?? temps are used on branch/merge blocks (#99).
                    $builder->branchIf($condition, $leftEntry, $rightEntry);
                    $leftTail = \PHPCompiler\JIT\CoalesceHelper::compileBranch($this, $func, $op->block1, $leftEntry);
                    $rightTail = \PHPCompiler\JIT\CoalesceHelper::compileBranch($this, $func, $op->block2, $rightEntry);
                    // Both branches compile; right-side literal metadata must not fold builtins (#764).
                    if ($this->context->hasVariableOp($coalesceResult)) {
                        $coalesceVar = $this->context->getVariableFromOp($coalesceResult);
                        $coalesceVar->compileTimeString = null;
                        $coalesceVar->compileTimeConstantName = null;
                        $coalesceVar->compileTimeEnumCase = null;
                    }
                    // ??= arms persist the object store then copy fetch-arm objectPropertySlot
                    // onto the merge temp (#33748). That GEP does not dominate coalesce_merge
                    // or a nested outer ?? — module verify "Instruction does not dominate
                    // all uses" for `$a->p ??= $b->q ??= 9` (#33760, peer TYPE_NULLSAFE #32988).
                    $this->reseatCoalesceResultAfterPropertyArms($coalesceResult);
                    if (null !== $op->block3) {
                        $mergeBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'coalesce_merge');
                        $builder->positionAtEnd($leftTail);
                        if (null === $leftTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($rightTail);
                        if (null === $rightTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($mergeBb);
                        // Refresh inherited locals after ?? (#866). IncludeBindingEmitHelper skips
                        // in-flight coalesceAssignTargets (e.g. $scriptBase) so MiniWebApp AOT
                        // does not munmap (#20507).
                        if ($this->context->inlineIncludeDepth > 0) {
                            \PHPCompiler\JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                        }
                        $mergeLimit = \PHPCompiler\JIT\CoalesceHelper::mergeBlockOpcodeLimit($op->block3);
                        $savedSynthetic = $op->block3->syntheticCfgBranch ?? false;
                        if (null !== $mergeLimit && $mergeLimit < $op->block3->nOpCodes) {
                            $op->block3->syntheticCfgBranch = true;
                        }
                        try {
                            $merged = $this->compileBlockInternal($func, $op->block3, $mergeLimit, $mergeBb, 0, false, ...$args);
                        } finally {
                            $op->block3->syntheticCfgBranch = $savedSynthetic;
                        }
                        unset($this->context->coalesceAssignTargets[$coalesceResult]);
                        $this->releaseCoalesceMergeSlotMapping($block, $coalesceResult);
                        $this->clearCoalesceFetchArmPropertySlotsInScope();
                        if ($this->context->inlineIncludeDepth > 0) {
                            // Do not set inlineIncludeExitBlock to the ?? merge block (#866, #784).
                            break;
                        }

                        return $merged;
                    }
                    unset($this->context->coalesceAssignTargets[$coalesceResult]);
                    $this->releaseCoalesceMergeSlotMapping($block, $coalesceResult);
                    $this->clearCoalesceFetchArmPropertySlotsInScope();
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Two-branch ?? without merge: continue in the including TU (#866).
                        break;
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_NULLSAFE:
                    $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context);
                    if (null === $branchBlock) {
                        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'nullsafe_branch');
                        $branchBlock = \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context) ?? $origBasicBlock;
                    }
                    $builder->positionAtEnd($branchBlock);
                    $nullsafeResult = $block->getOperand($op->arg1);
                    $this->context->coalesceAssignTargets[$nullsafeResult] = true;
                    // Pre-allocate one stack slot before branches so null/fetch/merge all write
                    // the same alloca — otherwise AOT coalesce/assign reads an untouched temp (#26818).
                    $this->ensureCoalesceMergeStackSlot($nullsafeResult);
                    $nullsafeMergeSlot = $block->slotForOperand($nullsafeResult);
                    if (null !== $nullsafeMergeSlot) {
                        $this->context->coalesceMergeSlotOperands[$nullsafeMergeSlot] = $nullsafeResult;
                    }
                    $receiver = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    // Compile-time null: only lower the null arm. Compiling the fetch arm for
                    // `$o?->m()` still runs METHODCALL_INIT and fatals with
                    // "Call to undefined method object::m()" under AOT (#34713 /
                    // ZEND_NULLSAFE_METHODCALL).
                    $knownNullReceiver = Variable::TYPE_NULL === $receiver->type
                        || $receiver->isNullConstant;
                    $isNull = $knownNullReceiver
                        ? $this->context->getTypeFromString('int1')->constInt(1, false)
                        : \PHPCompiler\JIT\NullsafeHelper::isReceiverNull(
                            $this,
                            $receiver,
                            $op->nullsafeMethodCall
                        );
                    // Mirror ?? lowering: branchIf targets entry blocks; merge from branch tails (#3219).
                    $nullTail = \PHPCompiler\JIT\NullsafeHelper::compileBranch($this, $func, $op->block1);
                    $fetchTail = null;
                    if (!$knownNullReceiver) {
                        $fetchTail = \PHPCompiler\JIT\NullsafeHelper::compileBranch($this, $func, $op->block2);
                    }
                    if ($this->context->hasVariableOp($nullsafeResult)) {
                        $nullsafeVar = $this->context->getVariableFromOp($nullsafeResult);
                        $nullsafeVar->compileTimeString = null;
                        $nullsafeVar->compileTimeConstantName = null;
                        $nullsafeVar->compileTimeEnumCase = null;
                        // Fetch-arm propertySlotPtr (void**) does not dominate the merge block —
                        // later loads (var_dump / ARG_SEND) must use the coalesce __value__ slot (#32988).
                        $nullsafeVar->objectPropertySlot = null;
                        $nullsafeVar->objectPropertyType = null;
                        $nullsafeVar->objectPropertyReceiver = null;
                        $nullsafeVar->objectPropertyName = null;
                        $nullsafeVar->objectPropertyClassName = null;
                        $nullsafeVar->objectPropertyDnfArms = null;
                    }
                    $nullEntry = $this->jitBranchEntryBlock($op->block1, $func);
                    $builder->positionAtEnd($branchBlock);
                    // Do not free php-cfg "dead" operands here; ?-> temps are used on branch/merge blocks (#3219).
                    if ($knownNullReceiver) {
                        $builder->branch($nullEntry);
                    } else {
                        $fetchEntry = $this->jitBranchEntryBlock($op->block2, $func);
                        $builder->branchIf($isNull, $nullEntry, $fetchEntry);
                    }
                    if (null !== $op->block3) {
                        // Fetch arm may have rebound the result to a property-backed Variable;
                        // reseat on a plain merge alloca before merge-block uses (#32988).
                        $this->ensureCoalesceMergeStackSlot($nullsafeResult);
                        if ($this->context->hasVariableOp($nullsafeResult)) {
                            $mergeSeat = $this->context->getVariableFromOp($nullsafeResult);
                            $mergeSeat->objectPropertySlot = null;
                            $mergeSeat->objectPropertyType = null;
                            $mergeSeat->objectPropertyReceiver = null;
                            $mergeSeat->objectPropertyName = null;
                            $mergeSeat->objectPropertyClassName = null;
                            $mergeSeat->objectPropertyDnfArms = null;
                            $mergeSeat->compileTimeString = null;
                            $mergeSeat->compileTimeConstantName = null;
                            $mergeSeat->compileTimeEnumCase = null;
                        }
                        $mergeBb = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'nullsafe_merge');
                        $builder->positionAtEnd($nullTail);
                        if (null === $nullTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        if (null !== $fetchTail) {
                            $builder->positionAtEnd($fetchTail);
                            if (null === $fetchTail->getTerminator()) {
                                $builder->branch($mergeBb);
                            }
                        }
                        $builder->positionAtEnd($mergeBb);
                        // Mirror ?? : refresh inherited locals; skip in-flight assign targets (#20507).
                        if ($this->context->inlineIncludeDepth > 0) {
                            \PHPCompiler\JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                        }
                        $mergeLimit = \PHPCompiler\JIT\CoalesceHelper::mergeBlockOpcodeLimit($op->block3);
                        $savedSynthetic = $op->block3->syntheticCfgBranch ?? false;
                        if (null !== $mergeLimit && $mergeLimit < $op->block3->nOpCodes) {
                            $op->block3->syntheticCfgBranch = true;
                        }
                        try {
                            $merged = $this->compileBlockInternal($func, $op->block3, $mergeLimit, $mergeBb, 0, false, ...$args);
                        } finally {
                            $op->block3->syntheticCfgBranch = $savedSynthetic;
                        }
                        unset($this->context->coalesceAssignTargets[$nullsafeResult]);
                        if ($this->context->inlineIncludeDepth > 0) {
                            // Mirror ?? lowering: stay in the including TU (#866, #784, #15149).
                            break;
                        }

                        return $merged;
                    }
                    unset($this->context->coalesceAssignTargets[$nullsafeResult]);
                    if ($this->context->inlineIncludeDepth > 0) {
                        break;
                    }

                    return $origBasicBlock;
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
                    try {
                        $closureVar = \PHPCompiler\JIT\FromCallableHelper::createClosureVariable($this->context, $block, $op);
                        if (null !== $closureVar->closureCall) {
                            $this->context->fccClosureCallByResultSlot[(int) $op->arg1] = $closureVar->closureCall;
                        }
                        $this->assignOperand($block->getOperand($op->arg1), $closureVar, true);
                    } catch (\TypeError $e) {
                        // Closure::fromCallable TypeError precedes Error (TypeError extends Error) (#27138).
                        $file = '';
                        $line = 0;
                        if (null !== $op->sourceLocation) {
                            $file = $op->sourceLocation->filename;
                            $line = $op->sourceLocation->startLine;
                        }
                        if ('' === $file) {
                            $file = $block->scriptPath();
                            if ('' === $file) {
                                $file = $this->context->jitAotEntryScriptPath;
                            }
                        }
                        if ([] !== $this->context->tryCatch->handlerStack) {
                            \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
                                $this->context,
                                'TypeError',
                                $e->getMessage(),
                                $this,
                                $file,
                                $line
                            );
                        } else {
                            \PHPCompiler\JIT\TryCatchHelper::emitPendTypeErrorForCaller($this->context, $e->getMessage());
                            \PHPCompiler\JIT\Builtin\TypeErrorRaise::registerDeclarations($this->context);
                            \PHPCompiler\JIT\Builtin\TypeErrorRaise::ensureLinked($this->context);
                            \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitRaise($this->context, $e->getMessage());
                            \PHPCompiler\JIT\Builtin\TypeErrorRaise::emitAbortIfPendingForStandaloneMain($this->context);
                        }
                        $this->context->builder->clearInsertionPosition();

                        return $origBasicBlock;
                    } catch (\Error $e) {
                        // Compile-time FCC reject → catchable runtime Error at FCC site (#24397, #27106).
                        $file = '';
                        $line = 0;
                        if (null !== $op->sourceLocation) {
                            $file = $op->sourceLocation->filename;
                            $line = $op->sourceLocation->startLine;
                        }
                        if ('' === $file) {
                            $file = $block->scriptPath();
                            if ('' === $file) {
                                $file = $this->context->jitAotEntryScriptPath;
                            }
                        }
                        if ([] !== $this->context->tryCatch->handlerStack) {
                            \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
                                $this->context,
                                'Error',
                                $e->getMessage(),
                                $this,
                                $file,
                                $line
                            );
                        } else {
                            // Pend + abort_if_pending prints Zend-shaped fatal (not libc abort) (#27106).
                            \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                            \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                            \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $e->getMessage());
                            \PHPCompiler\JIT\Builtin\ErrorRaise::emitAbortIfPendingForStandaloneMain($this->context);
                        }
                        // Stop like TYPE_THROW — further ops insert before the terminator (#27106).
                        $this->context->builder->clearInsertionPosition();

                        return $origBasicBlock;
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
