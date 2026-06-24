<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCompiler\JIT\Builtin\ExceptionHandlerJitRuntime;
use PHPCompiler\JIT\Builtin\JitReturnPending;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\JIT\Builtin\ScriptExit;
use PHPCompiler\OpCode;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * LLVM lowering for try/catch/throw within a single JIT function (issues #57, #2084, #1056, #2157).
 */
final class TryCatchHelper
{
    /** @var list<TryCatchHandler> */
    public array $handlerStack = [];

    /** @var array<int, TryCatchHandler> merge block id => handler */
    public array $mergeHandlers = [];

    /**
     * @return list<array{op: OpCode, catchTypes: list<string>}>
     */
    public static function collectCatchOps(Block $handlerBlock, int $afterTryIndex): array
    {
        $arms = [];
        $n = $handlerBlock->nOpCodes;
        for ($j = $afterTryIndex + 1; $j < $n; ++$j) {
            $next = $handlerBlock->opCodes[$j];
            if (OpCode::TYPE_JUMP === $next->type) {
                continue;
            }
            if (OpCode::TYPE_CATCH === $next->type) {
                $types = [];
                $encoded = $next->catchTypes;
                if (null !== $encoded && '' !== $encoded) {
                    foreach (explode('|', $encoded) as $typeName) {
                        $typeName = strtolower(ltrim($typeName, '\\'));
                        if ('' !== $typeName) {
                            $types[] = $typeName;
                        }
                    }
                }
                $arms[] = ['op' => $next, 'catchTypes' => $types];
                continue;
            }
            if (OpCode::TYPE_FINALLY === $next->type) {
                break;
            }
            break;
        }

        return $arms;
    }

    public static function findFinallyOp(Block $handlerBlock, int $afterTryIndex): ?OpCode
    {
        $n = $handlerBlock->nOpCodes;
        for ($j = $afterTryIndex + 1; $j < $n; ++$j) {
            $next = $handlerBlock->opCodes[$j];
            if (OpCode::TYPE_CATCH === $next->type) {
                continue;
            }
            if (OpCode::TYPE_FINALLY === $next->type) {
                return $next;
            }
            break;
        }

        return null;
    }

    /**
     * Defer LLVM return through an active try/finally handler (#4246).
     */
    public static function deferReturnIfNeeded(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $block,
        bool $isVoid,
        ?Variable $returnVar
    ): bool {
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        if (null === $handler || null === $handler->finallyOp) {
            return false;
        }
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $finallyBb = self::finallyBbFor($jit, $func, $context, $handler, [], false);
        JitReturnPending::setPending($context, $returnVar, $isVoid);
        $builder = $context->builder;
        $returnBlock = $builder->getInsertBlock();
        if (null === $returnBlock || null !== $returnBlock->getTerminator()) {
            $returnBlock = self::appendBlock($func, 'return_defer_finally_'.self::blockSuffix($handler));
            $builder->positionAtEnd($returnBlock);
        } else {
            $builder->positionAtEnd($returnBlock);
        }
        $builder->branch($finallyBb);

        return true;
    }

    public static function beginTry(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        Block $handlerBlock,
        OpCode $tryOp,
        int $tryOpcodeIndex,
        array $args
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        $mergeBlock = $tryOp->block2;
        if (null === $mergeBlock) {
            throw new \LogicException('TYPE_TRY requires merge block (block2)');
        }
        $arms = self::collectCatchOps($handlerBlock, $tryOpcodeIndex);
        $handler = new TryCatchHandler($mergeBlock, $arms);
        $handler->finallyOp = self::findFinallyOp($handlerBlock, $tryOpcodeIndex);
        $handler->postTryOpcodesRemaining = self::countPostTryOpcodes($handlerBlock, $tryOpcodeIndex);
        $context->tryCatch->handlerStack[] = $handler;
        $context->tryCatch->mergeHandlers[spl_object_id($mergeBlock)] = $handler;

        $builder = $context->builder;
        $branchBlock = null;
        try {
            $branchBlock = $builder->getInsertBlock();
        } catch (\Throwable) {
        }
        if (null === $branchBlock || null !== $branchBlock->getTerminator()) {
            $cached = $context->scope->blockStorage[$handlerBlock] ?? null;
            if (null !== $cached && null === $cached->getTerminator()) {
                $branchBlock = $cached;
            } elseif (null === $branchBlock || null !== $branchBlock->getTerminator()) {
                $branchBlock = self::appendBlock($func, 'try_branch_'.self::blockSuffix($handler));
            }
        }
        if (null === $branchBlock) {
            throw new \LogicException('TYPE_TRY lowering requires an active LLVM basic block');
        }
        $context->scope->blockStorage[$handlerBlock] = $branchBlock;
        $builder->positionAtEnd($branchBlock);
        $builder->call($context->lookupFunction('phpc_jit_clear_throw_pending'));
        $builder->call($context->lookupFunction('phpc_jit_clear_return_pending'));
        $mergeHeaderBb = $context->scope->blockStorage[$mergeBlock] ?? null;
        if (!$handler->mergeBodyCompiled) {
            if (null === $mergeHeaderBb) {
                $mergeHeaderBb = self::appendBlock($func, 'try_merge_'.self::blockSuffix($handler));
            }
            $context->scope->blockStorage[$mergeBlock] = $mergeHeaderBb;
            $context->scope->blockEntryStorage[$mergeBlock] = $mergeHeaderBb;
            $mergeBodyBb = self::appendBlock($func, 'try_merge_body_'.self::blockSuffix($handler));
            $handler->mergeBodyLlvmBb = $mergeBodyBb;
            if (null === $mergeBodyBb->getTerminator()) {
                $jit->compileIncludedAtEntry($func, $handler->mergeBlock, $mergeBodyBb);
            }
            $builder->positionAtEnd($mergeHeaderBb);
            if (null === $mergeHeaderBb->getTerminator()) {
                $builder->branch($mergeBodyBb);
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'try_merge_after_compile');
            $handler->mergeBodyCompiled = true;
        } elseif (null === $mergeHeaderBb) {
            $mergeHeaderBb = self::appendBlock($func, 'try_merge_'.self::blockSuffix($handler));
            $context->scope->blockStorage[$mergeBlock] = $mergeHeaderBb;
            $context->scope->blockEntryStorage[$mergeBlock] = $mergeHeaderBb;
        }
        $mergeBb = $mergeHeaderBb;
        $builder->positionAtEnd($branchBlock);
        if (null !== $handler->finallyOp) {
            self::ensureFinallyLowering($jit, $func, $context, $handler, $args);
        }
        $handler->dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        self::emitMergeEntryCheck($jit, $func, $context, $mergeBlock, $mergeBb, $args);
        $jit->compileSubBlock($func, $tryOp->block1, ...$args);
        $tryTail = $builder->getInsertBlock();
        if (null !== $tryTail && null === $tryTail->getTerminator()) {
            $builder->positionAtEnd($tryTail);
            if (null !== $handler->finallyBb) {
                $builder->branch($handler->finallyBb);
            } elseif (null !== $handler->mergeEntryBb) {
                $builder->branch($handler->mergeEntryBb);
            }
        }
        $tryEntry = $context->scope->blockStorage[$tryOp->block1];
        $builder->positionAtEnd($branchBlock);
        if (0 === $context->inlineIncludeDepth) {
            $context->freeDeadVariables($func, $branchBlock, $handlerBlock);
        }
        if (null === $branchBlock->getTerminator()) {
            $builder->branch($tryEntry);
        }
    }

    /**
     * Register try/catch dispatch for generator resume segments (#4069).
     *
     * Try body opcodes are compiled by {@see GeneratorHelper::compileYieldPrefix}; this only
     * wires merge/dispatch blocks and branches into the try-body LLVM entry.
     */
    public static function beginTryGeneratorResume(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        Block $handlerBlock,
        OpCode $tryOp,
        int $tryOpcodeIndex,
        array $args,
        BasicBlock $branchBlock,
        BasicBlock $tryBodyEntryBb
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $mergeBlock = $tryOp->block2;
        if (null === $mergeBlock) {
            throw new \LogicException('TYPE_TRY requires merge block (block2)');
        }
        $arms = self::collectCatchOps($handlerBlock, $tryOpcodeIndex);
        $handler = new TryCatchHandler($mergeBlock, $arms);
        $handler->postTryOpcodesRemaining = self::countPostTryOpcodes($handlerBlock, $tryOpcodeIndex);
        $context->tryCatch->handlerStack[] = $handler;
        $context->tryCatch->mergeHandlers[spl_object_id($mergeBlock)] = $handler;

        $builder = $context->builder;
        $context->scope->blockStorage[$handlerBlock] = $branchBlock;
        $builder->positionAtEnd($branchBlock);
        $mergeBb = $context->scope->blockStorage[$mergeBlock] ?? null;
        if (null === $mergeBb) {
            $mergeBb = self::appendBlock($func, 'try_merge_'.self::blockSuffix($handler));
        }
        if (!$handler->mergeBodyCompiled) {
            if (!$context->compilingGeneratorResume) {
                $jit->compileIncludedAtEntry($func, $handler->mergeBlock, $mergeBb);
            }
            $handler->mergeBodyCompiled = true;
        }
        $handler->dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        self::emitMergeEntryCheck($jit, $func, $context, $mergeBlock, $mergeBb, $args);
        if (null !== $tryOp->block1) {
            $context->scope->blockStorage[$tryOp->block1] = $tryBodyEntryBb;
        }
        $builder->positionAtEnd($branchBlock);
        $builder->branch($tryBodyEntryBb);
    }

    public static function emitMergeEntryCheck(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        Block $mergeCfgBlock,
        BasicBlock $mergeBb,
        array $args
    ): void {
        $handler = $context->tryCatch->mergeHandlers[spl_object_id($mergeCfgBlock)] ?? null;
        if (null === $handler || $handler->mergeEntryEmitted) {
            return;
        }
        $handler->mergeEntryEmitted = true;
        $dispatchBb = $handler->dispatchBb;
        if (null === $dispatchBb) {
            $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        }

        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $entryBb = self::appendBlock($func, 'try_merge_entry_'.self::blockSuffix($handler));
        $handler->mergeEntryBb = $entryBb;
        $builder->positionAtEnd($entryBb);
        $hasPending = $builder->call($context->lookupFunction('phpc_jit_has_throw_pending'));
        $i32 = $context->getTypeFromString('int32');
        $hasBool = $builder->icmp(
            Builder::INT_NE,
            $hasPending,
            $i32->constInt(0, false)
        );
        $builder->branchIf($hasBool, $dispatchBb, $mergeBb);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
    }

    /**
     * Raise a catchable Error inside an active try block (asymmetric visibility #4029).
     */
    public static function emitCatchableErrorMessage(
        Context $context,
        \PHPCompiler\JIT $jit,
        string $message
    ): void {
        self::emitCatchableClassError($context, 'Error', $message, $jit);
    }

    public static function emitCatchableClassError(
        Context $context,
        string $className,
        string $message,
        ?\PHPCompiler\JIT $jit = null
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $handler = self::resolveThrowHandler($context);
        if (null === $handler) {
            ErrorRaise::emitRaise($context, $message);

            return;
        }
        $func = $context->builder->getInsertBlock()->getParent();
        assert($func instanceof Function_);
        $dispatchBb = null !== $jit
            ? self::dispatchBbFor($jit, $func, $context, $handler, [])
            : $handler->dispatchBb;
        if (null === $dispatchBb) {
            ErrorRaise::emitRaise($context, $message);

            return;
        }

        $object = $context->type->object;
        $classId = $object->lookup($className);
        $obj = $object->allocate($classId);
        $object->markObjectConstructed($obj);
        $msgStr = $context->builder->load($context->constantStringFromString($message));
        $msgVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $msgStr
        );
        $object->storeInstanceProperty($obj, $className, 'message', $msgVar);

        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        $context->builder->branch($dispatchBb);
    }

    public static function emitRethrow(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $block
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $builder = $context->builder;
        $objPtr = $context->getTypeFromString('__object__*');
        $active = $builder->call($context->lookupFunction('phpc_jit_get_active_catch'));
        $isNull = $builder->icmp(
            Builder::INT_EQ,
            $active,
            $objPtr->constNull()
        );
        $throwBlock = $builder->getInsertBlock();
        if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
            $throwBlock = self::appendBlock($func, 'rethrow_check');
            $builder->positionAtEnd($throwBlock);
        } else {
            $builder->positionAtEnd($throwBlock);
        }
        $outsideCatch = self::appendBlock($func, 'rethrow_outside_catch');
        $dispatchPath = self::appendBlock($func, 'rethrow_dispatch');
        $builder->branchIf($isNull, $outsideCatch, $dispatchPath);
        $builder->positionAtEnd($outsideCatch);
        $handler = self::resolveThrowHandler($context);
        if (null !== $handler) {
            self::emitCatchableClassError(
                $context,
                'LogicException',
                'Cannot use "throw;" outside of a catch block',
                $jit
            );
        } else {
            ErrorRaise::emitRaise($context, 'Cannot use "throw;" outside of a catch block');
        }
        $builder->positionAtEnd($dispatchPath);
        $handler = self::resolveThrowHandler($context);
        if (null === $handler) {
            $context->freeDeadVariables($func, $builder->getInsertBlock(), $block);
            $builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);

            return;
        }
        $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, []);
        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $active);
        $builder->branch($dispatchBb);
    }

    public static function emitThrow(
        \PHPCompiler\JIT $jit,
        Context $context,
        Function_ $func,
        Block $block,
        OpCode $op
    ): void {
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $handler = self::resolveThrowHandler($context);
        if (null === $handler) {
            $builder = $context->builder;
            $throwBlock = self::probeInsertBlock($builder);
            if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
                $throwBlock = self::appendBlock($func, 'throw_uncaught');
                $builder->positionAtEnd($throwBlock);
            } else {
                $builder->positionAtEnd($throwBlock);
            }
            $context->freeDeadVariables($func, $throwBlock, $block);
            $thrown = $context->getVariableFromOp($block->getOperand($op->arg1));
            if (VMVariable::TYPE_ENUM_CASE === $thrown->type) {
                self::emitCatchableClassError(
                    $context,
                    \PHPCompiler\VM\ExceptionSupport::CLASS_ERROR,
                    \PHPCompiler\VM\ExceptionSupport::THROW_NON_THROWABLE_MESSAGE,
                    $jit
                );

                return;
            }
            if (
                Variable::TYPE_OBJECT !== $thrown->type
                && Variable::TYPE_VALUE !== $thrown->type
            ) {
                self::emitCatchableClassError(
                    $context,
                    \PHPCompiler\VM\ExceptionSupport::CLASS_ERROR,
                    \PHPCompiler\VM\ExceptionSupport::THROW_ONLY_OBJECTS_MESSAGE,
                    $jit
                );

                return;
            }
            $obj = self::loadThrownObject($context, $thrown);
            self::emitUncaughtUserHandlerOrAbort($context, $obj);

            return;
        }
        $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, []);

        $thrown = $context->getVariableFromOp($block->getOperand($op->arg1));
        if (
            Variable::TYPE_OBJECT !== $thrown->type
            && Variable::TYPE_VALUE !== $thrown->type
            && VMVariable::TYPE_ENUM_CASE !== $thrown->type
        ) {
            self::emitCatchableClassError(
                $context,
                \PHPCompiler\VM\ExceptionSupport::CLASS_ERROR,
                \PHPCompiler\VM\ExceptionSupport::THROW_ONLY_OBJECTS_MESSAGE,
                $jit
            );

            return;
        }

        $builder = $context->builder;
        $throwBlock = self::probeInsertBlock($builder);
        if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
            $throwBlock = self::appendBlock($func, 'throw_pending_'.self::blockSuffix($handler));
            $builder->positionAtEnd($throwBlock);
        } else {
            $builder->positionAtEnd($throwBlock);
        }

        $isThrowable = ReflectionBuiltinHelper::emitInstanceOf($context, $thrown, 'Throwable');
        $isBool = Variable::TYPE_NATIVE_BOOL === $isThrowable->type
            ? $isThrowable->value
            : $context->helper->loadValue($isThrowable);
        $validThrow = self::appendBlock($func, 'throw_valid_'.self::blockSuffix($handler));
        $invalidThrow = self::appendBlock($func, 'throw_non_throwable_'.self::blockSuffix($handler));
        $builder->branchIf($isBool, $validThrow, $invalidThrow);
        $builder->positionAtEnd($invalidThrow);
        self::emitCatchableClassError(
            $context,
            \PHPCompiler\VM\ExceptionSupport::CLASS_ERROR,
            \PHPCompiler\VM\ExceptionSupport::THROW_NON_THROWABLE_MESSAGE,
            $jit
        );
        $builder->positionAtEnd($validThrow);

        $obj = $context->helper->loadValue($thrown);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $thrown);
            $obj = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        }

        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        $builder->branch($dispatchBb);
    }

    /**
     * @param list<Variable> $args
     */
    private static function dispatchBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        if (null !== $handler->dispatchBb) {
            $parent = $handler->dispatchBb->getParent();
            if ($parent instanceof Function_ && $parent === $func) {
                return $handler->dispatchBb;
            }
            $handler->dispatchBb = null;
        }

        return $handler->dispatchBb = self::buildDispatch($jit, $func, $context, $handler, $args);
    }

    /**
     * @param list<Variable> $args
     */
    private static function buildDispatch(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        if (null !== $handler->dispatchBb) {
            $existing = $handler->dispatchBb->getParent();
            if ($existing instanceof Function_ && $existing === $func) {
                return $handler->dispatchBb;
            }
        }

        $suffix = self::blockSuffix($handler);
        $dispatch = self::appendBlock($func, 'try_catch_dispatch_'.$suffix);
        // Pin before catch lowering: emitMergeEntryCheck re-enters dispatchBbFor mid-build (#4041).
        $handler->dispatchBb = $dispatch;
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($dispatch);
        $builder->call($context->lookupFunction('phpc_jit_clear_return_pending'));

        $pendingObj = $builder->call($context->lookupFunction('phpc_jit_take_throw_pending'));
        $mergeBody = $context->scope->blockStorage[$handler->mergeBlock] ?? null;

        $uncaught = self::appendBlock($func, 'try_uncaught_'.$suffix);
        $nextCatch = $dispatch;
        $singleArm = 1 === count($handler->catchArms);

        foreach ($handler->catchArms as $arm) {
            $catchOp = $arm['op'];
            $types = $arm['catchTypes'];
            $catchCfg = $catchOp->block1;
            $cachedCatchBb = null !== $catchCfg
                ? ($context->scope->blockStorage[$catchCfg] ?? null)
                : null;
            $catchBodyBb = $cachedCatchBb ?? self::appendBlock($func, 'try_catch_match_'.$suffix);
            $noMatchBb = self::appendBlock($func, 'try_catch_nomatch_'.$suffix);
            $catchSetupBb = self::appendBlock($func, 'try_catch_setup_'.$suffix);

            $builder->positionAtEnd($nextCatch);
            if ([] === $types || $singleArm) {
                $builder->branch($catchSetupBb);
            } else {
                $checkBb = $nextCatch;
                $typeCount = count($types);
                foreach ($types as $idx => $typeName) {
                    $thrownVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $pendingObj);
                    $isInstance = ReflectionBuiltinHelper::emitInstanceOf($context, $thrownVar, $typeName);
                    $isBool = Variable::TYPE_NATIVE_BOOL === $isInstance->type
                        ? $isInstance->value
                        : $context->helper->loadValue($isInstance);
                    $isLast = $idx === $typeCount - 1;
                    if ($isLast) {
                        $builder->branchIf($isBool, $catchSetupBb, $noMatchBb);
                    } else {
                        $nextCheck = self::appendBlock($func, 'try_catch_type_next_'.$suffix);
                        $builder->branchIf($isBool, $catchSetupBb, $nextCheck);
                        $checkBb = $nextCheck;
                        $builder->positionAtEnd($checkBb);
                    }
                }
            }

            $builder->positionAtEnd($catchSetupBb);
            $builder->call($context->lookupFunction('phpc_jit_set_active_catch'), $pendingObj);
            // Detach only while lowering the catch arm so throws inside the arm use outer
            // handlers; restore before returning so try-body throw lowering still sees this try
            // (#4886, #10527 — buildDispatch runs before compileSubBlock in beginTry).
            $savedThrowHandlerStack = $context->tryCatch->handlerStack;
            self::detachHandlerFromThrowStack($context, $handler);
            if (null !== $catchOp->arg3) {
                $operand = $catchOp->block1->getOperand((int) $catchOp->arg3);
                $caughtVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $pendingObj);
                $jit->assignOperandForced($operand, $caughtVar);
            }
            $builder->branch($catchBodyBb);

            if (null === $cachedCatchBb) {
                if ($context->compilingGeneratorResume && null !== $catchOp->block1) {
                    $catchResume = $context->generatorCatchDispatchEntry[spl_object_id($catchOp->block1)] ?? null;
                    if (null !== $catchResume) {
                        $builder->branch($catchResume);
                        $context->tryCatch->handlerStack = $savedThrowHandlerStack;
                        $nextCatch = $noMatchBb;
                        $builder->positionAtEnd($nextCatch);

                        continue;
                    }
                }
                if ($context->compilingFiberResume && null !== $catchOp->block1) {
                    $catchResume = $context->fiberCatchDispatchEntry[spl_object_id($catchOp->block1)] ?? null;
                    if (null !== $catchResume) {
                        $builder->branch($catchResume);
                        $context->tryCatch->handlerStack = $savedThrowHandlerStack;
                        $nextCatch = $noMatchBb;
                        $builder->positionAtEnd($nextCatch);

                        continue;
                    }
                }
                $jit->compileCatchArmAtEntry($func, $catchOp->block1, $catchBodyBb, ...$args);
                $catchTail = $context->builder->getInsertBlock();
                $builder->positionAtEnd($catchTail);
                if (null === $catchTail->getTerminator()) {
                    if (null !== $handler->finallyBb) {
                        $builder->call($context->lookupFunction('phpc_jit_clear_throw_pending'));
                        $builder->branch($handler->finallyBb);
                    } elseif (null !== $mergeBody) {
                        $builder->call($context->lookupFunction('phpc_jit_clear_active_catch'));
                        $builder->branch($mergeBody);
                    }
                }
            }
            $context->tryCatch->handlerStack = $savedThrowHandlerStack;

            $nextCatch = $noMatchBb;
            $builder->positionAtEnd($nextCatch);
        }

        if (null !== $handler->finallyBb) {
            $beforeFinally = self::appendBlock($func, 'try_nomatch_finally_'.$suffix);
            $builder->branch($beforeFinally);
            $builder->positionAtEnd($beforeFinally);
            $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $pendingObj);
            $builder->branch($handler->finallyBb);
        } else {
            $builder->branch($uncaught);
            $builder->positionAtEnd($uncaught);
            $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $pendingObj);
            self::emitUncaughtUserHandlerOrAbort($context, $pendingObj);
        }

        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $dispatch;
    }

    /**
     * TYPE_CATCH / TYPE_FINALLY after TYPE_TRY are lowered inside beginTry; skip CFG opcodes (#2114).
     */
    public static function finishPostTryOpcode(Context $context): void
    {
        if ([] === $context->tryCatch->handlerStack) {
            return;
        }
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)];
        if ($handler->postTryOpcodesRemaining <= 0) {
            return;
        }
        --$handler->postTryOpcodesRemaining;
        if (0 === $handler->postTryOpcodesRemaining) {
            self::popHandler($context);
        }
    }

    public static function popHandler(Context $context): void
    {
        if ([] === $context->tryCatch->handlerStack) {
            return;
        }
        $handler = array_pop($context->tryCatch->handlerStack);
        unset($context->tryCatch->mergeHandlers[spl_object_id($handler->mergeBlock)]);
    }

    private static function loadThrownObject(Context $context, Variable $thrown): Value
    {
        $obj = $context->helper->loadValue($thrown);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $thrown);

            return $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        }

        return $obj;
    }

    private static function emitUncaughtUserHandlerOrAbort(Context $context, Value $exceptionObj): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ex_handler_cont');
        ExceptionHandlerJitRuntime::ensureLinked($context);
        $builder = $context->builder;
        $i32 = $context->getTypeFromString('int32');
        $handled = $builder->call(
            $context->lookupFunction('__phpc_exception_handler_dispatch'),
            $exceptionObj
        );
        $func = $builder->getInsertBlock()->getParent();
        if (!$func instanceof Function_) {
            throw new \LogicException('emitUncaughtUserHandlerOrAbort requires parent function');
        }
        $handledBb = self::appendBlock($func, 'ex_handler_handled');
        $abortBb = self::appendBlock($func, 'ex_handler_abort');
        $builder->branchIf(
            $builder->icmp(Builder::INT_NE, $handled, $i32->constInt(0, false)),
            $handledBb,
            $abortBb
        );
        $builder->positionAtEnd($handledBb);
        ScriptExit::emitLibcExitWithStatus($context, $context->getTypeFromString('int64')->constInt(0, false));
        $builder->positionAtEnd($abortBb);
        $builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ex_handler_after_abort');
    }

    /**
     * Active try handler for throw/rethrow dispatch (innermost unconsumed try).
     */
    public static function resolveThrowHandler(Context $context): ?TryCatchHandler
    {
        if ([] === $context->tryCatch->handlerStack) {
            return null;
        }

        return $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)];
    }

    /**
     * After entering a catch arm, further throws use enclosing try handlers (#4886).
     */
    public static function detachHandlerFromThrowStack(Context $context, TryCatchHandler $handler): void
    {
        $stack = $context->tryCatch->handlerStack;
        $idx = array_search($handler, $stack, true);
        if (false === $idx) {
            return;
        }
        array_splice($context->tryCatch->handlerStack, $idx, 1);
    }

    private static function countPostTryOpcodes(Block $handlerBlock, int $afterTryIndex): int
    {
        $count = 0;
        $n = $handlerBlock->nOpCodes;
        for ($j = $afterTryIndex + 1; $j < $n; ++$j) {
            $type = $handlerBlock->opCodes[$j]->type;
            if (OpCode::TYPE_CATCH === $type || OpCode::TYPE_FINALLY === $type) {
                ++$count;
                continue;
            }
            break;
        }

        return $count;
    }

    /**
     * @param list<Variable> $args
     */
    private static function finallyBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args,
        bool $compileBody
    ): BasicBlock {
        if (null !== $handler->finallyBb) {
            if ($compileBody && !$handler->finallyBodyCompiled) {
                self::compileFinallyBody($jit, $func, $context, $handler, $args);
            }

            return $handler->finallyBb;
        }
        if (null === $handler->finallyOp || null === $handler->finallyOp->block1) {
            throw new \LogicException('finally lowering requested without TYPE_FINALLY');
        }
        JitReturnPending::registerDeclarations($context);
        JitReturnPending::ensureLinked($context);
        $finallyCfg = $handler->finallyOp->block1;
        $finallyBb = $context->scope->blockStorage[$finallyCfg] ?? null;
        if (null !== $finallyBb && null !== $handler->mergeBodyLlvmBb && $finallyBb === $handler->mergeBodyLlvmBb) {
            $finallyBb = null;
        }
        if (null === $finallyBb) {
            $finallyBb = self::appendBlock($func, 'try_finally_body_'.self::blockSuffix($handler));
            $context->scope->blockStorage[$finallyCfg] = $finallyBb;
        }
        $handler->finallyBb = $finallyBb;
        if ($compileBody && !$handler->finallyBodyCompiled) {
            self::compileFinallyBody($jit, $func, $context, $handler, $args);
        }

        return $finallyBb;
    }

    /**
     * @param list<Variable> $args
     */
    private static function compileFinallyBody(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): void {
        if ($handler->finallyBodyCompiled || null === $handler->finallyBb || null === $handler->finallyOp?->block1) {
            return;
        }
        $handler->finallyBodyCompiled = true;
        $jit->compileFinallyAtEntry($func, $handler->finallyOp->block1, $handler->finallyBb, ...$args);
        $builder = $context->builder;
        $builder->positionAtEnd($handler->finallyBb);
        $finallyTail = $builder->getInsertBlock();
        if (null !== $finallyTail && null === $finallyTail->getTerminator()) {
            $builder->positionAtEnd($finallyTail);
            $builder->branch(self::finallyEpilogueBbFor($jit, $func, $context, $handler, $args));
        }
    }

    /**
     * @param list<Variable> $args
     */
    private static function ensureFinallyLowering(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        return self::finallyBbFor($jit, $func, $context, $handler, $args, true);
    }

    /**
     * @param list<Variable> $args
     */
    private static function finallyEpilogueBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler,
        array $args
    ): BasicBlock {
        if (null !== $handler->finallyEpilogueBb) {
            return $handler->finallyEpilogueBb;
        }
        $mergeBb = $context->scope->blockStorage[$handler->mergeBlock] ?? null;
        $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        $epilogue = self::appendBlock($func, 'try_finally_epilogue_'.self::blockSuffix($handler));
        $handler->finallyEpilogueBb = $epilogue;
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($epilogue);
        $i32 = $context->getTypeFromString('int32');
        $hasReturn = $builder->call($context->lookupFunction('phpc_jit_has_return_pending'));
        $returnResume = self::returnResumeBbFor($jit, $func, $context, $handler);
        $hasReturnBool = $builder->icmp(Builder::INT_NE, $hasReturn, $i32->constInt(0, false));
        $afterReturnCheck = self::appendBlock($func, 'try_finally_after_return_'.self::blockSuffix($handler));
        $builder->branchIf($hasReturnBool, $returnResume, $afterReturnCheck);
        $builder->positionAtEnd($afterReturnCheck);
        $hasThrow = $builder->call($context->lookupFunction('phpc_jit_has_throw_pending'));
        $hasThrowBool = $builder->icmp(Builder::INT_NE, $hasThrow, $i32->constInt(0, false));
        $propagate = self::appendBlock($func, 'try_finally_propagate_'.self::blockSuffix($handler));
        $uncaught = self::appendBlock($func, 'try_finally_uncaught_'.self::blockSuffix($handler));
        if (null !== $mergeBb) {
            $builder->branchIf($hasThrowBool, $propagate, $mergeBb);
        } else {
            $builder->branchIf($hasThrowBool, $propagate, $uncaught);
        }
        $builder->positionAtEnd($propagate);
        self::popHandler($context);
        $outer = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        if (null !== $outer && null !== $outer->dispatchBb) {
            $builder->branch($outer->dispatchBb);
        } else {
            $builder->branch($uncaught);
        }
        $builder->positionAtEnd($uncaught);
        $pendingObj = $builder->call($context->lookupFunction('phpc_jit_take_throw_pending'));
        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $pendingObj);
        $builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $epilogue;
    }

    private static function returnResumeBbFor(
        \PHPCompiler\JIT $jit,
        Function_ $func,
        Context $context,
        TryCatchHandler $handler
    ): BasicBlock {
        if (null !== $handler->returnResumeBb) {
            return $handler->returnResumeBb;
        }
        $resume = self::appendBlock($func, 'try_return_resume_'.self::blockSuffix($handler));
        $handler->returnResumeBb = $resume;
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($resume);
        $jit->emitPendingReturnResume($func);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }

        return $resume;
    }

    private static function blockSuffix(TryCatchHandler $handler): string
    {
        return (string) spl_object_id($handler);
    }

    private static function appendBlock(Function_ $func, string $name): BasicBlock
    {
        return $func->appendBasicBlock($name);
    }

    /** NestedJitCompileScope clears LLVM insertion position — getInsertBlock() throws (#8559). */
    private static function probeInsertBlock(Builder $builder): ?BasicBlock
    {
        try {
            return $builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }
}

final class TryCatchHandler
{
    /** Remaining TYPE_CATCH/TYPE_FINALLY CFG opcodes to skip after beginTry (#2114). */
    public int $postTryOpcodesRemaining = 0;

    public bool $mergeEntryEmitted = false;

    public bool $mergeBodyCompiled = false;

    public bool $finallyBodyCompiled = false;

    public ?OpCode $finallyOp = null;

    public ?BasicBlock $mergeEntryBb = null;

    public ?BasicBlock $dispatchBb = null;

    public ?BasicBlock $finallyBb = null;

    public ?BasicBlock $mergeBodyLlvmBb = null;

    public ?BasicBlock $finallyEpilogueBb = null;

    public ?BasicBlock $returnResumeBb = null;

    /**
     * @param list<array{op: OpCode, catchTypes: list<string>}> $catchArms
     */
    public function __construct(
        public Block $mergeBlock,
        public array $catchArms,
    ) {
    }
}
