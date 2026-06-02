<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\OpCode;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
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
        $branchBlock = $context->scope->blockStorage[$handlerBlock] ?? $builder->getInsertBlock();
        if (null === $branchBlock) {
            throw new \LogicException('TYPE_TRY lowering requires an active LLVM basic block');
        }
        $builder->positionAtEnd($branchBlock);
        $mergeBb = $context->scope->blockStorage[$mergeBlock] ?? null;
        if (null === $mergeBb) {
            $mergeBb = self::appendBlock($func, 'try_merge_'.self::blockSuffix($handler));
        }
        if (!$handler->mergeBodyCompiled) {
            $jit->compileIncludedAtEntry($func, $handler->mergeBlock, $mergeBb);
            $handler->mergeBodyCompiled = true;
        }
        $handler->dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, $args);
        self::emitMergeEntryCheck($jit, $func, $context, $mergeBlock, $mergeBb, $args);
        $jit->compileSubBlock($func, $tryOp->block1, ...$args);
        $tryTail = $builder->getInsertBlock();
        if (null !== $tryTail && null === $tryTail->getTerminator() && null !== $handler->mergeEntryBb) {
            $builder->positionAtEnd($tryTail);
            $builder->branch($handler->mergeEntryBb);
        }
        $tryEntry = $context->scope->blockStorage[$tryOp->block1];
        $builder->positionAtEnd($branchBlock);
        if (0 === $context->inlineIncludeDepth) {
            $context->freeDeadVariables($func, $branchBlock, $handlerBlock);
        }
        $builder->branch($tryEntry);
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
            $builder->positionAtEnd($saved);
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
        JitThrow::registerDeclarations($context);
        JitThrow::ensureLinked($context);
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        if (null === $handler) {
            ErrorRaise::emitRaise($context, $message);

            return;
        }
        $func = $context->builder->getInsertBlock()->getParent();
        assert($func instanceof Function_);
        $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, []);

        $object = $context->type->object;
        $classId = $object->lookup('Error');
        $obj = $object->allocate($classId);
        $object->markObjectConstructed($obj);
        $msgStr = $context->builder->load($context->constantStringFromString($message));
        $msgVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $msgStr
        );
        $object->storeInstanceProperty($obj, 'Error', 'message', $msgVar);

        $context->builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        $context->builder->branch($dispatchBb);
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
        $handler = $context->tryCatch->handlerStack[array_key_last($context->tryCatch->handlerStack)] ?? null;
        if (null === $handler) {
            $builder = $context->builder;
            $throwBlock = $builder->getInsertBlock();
            if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
                $throwBlock = self::appendBlock($func, 'throw_uncaught');
                $builder->positionAtEnd($throwBlock);
            } else {
                $builder->positionAtEnd($throwBlock);
            }
            $context->freeDeadVariables($func, $throwBlock, $block);
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);

            return;
        }
        $dispatchBb = self::dispatchBbFor($jit, $func, $context, $handler, []);

        $thrown = $context->getVariableFromOp($block->getOperand($op->arg1));
        $obj = $context->helper->loadValue($thrown);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $thrown);
            $obj = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        }

        $builder = $context->builder;
        $throwBlock = $builder->getInsertBlock();
        if (null === $throwBlock || null !== $throwBlock->getTerminator()) {
            $throwBlock = self::appendBlock($func, 'throw_pending_'.self::blockSuffix($handler));
            $builder->positionAtEnd($throwBlock);
        } else {
            $builder->positionAtEnd($throwBlock);
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
            $catchEntryBb = $catchBodyBb;
            if (null !== $cachedCatchBb && null !== $catchOp->arg3) {
                $catchEntryBb = self::appendBlock($func, 'try_catch_assign_'.$suffix);
            }
            $noMatchBb = self::appendBlock($func, 'try_catch_nomatch_'.$suffix);

            $builder->positionAtEnd($nextCatch);
            if ([] === $types || $singleArm) {
                $builder->branch($catchEntryBb);
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
                        $builder->branchIf($isBool, $catchEntryBb, $noMatchBb);
                    } else {
                        $nextCheck = self::appendBlock($func, 'try_catch_type_next_'.$suffix);
                        $builder->branchIf($isBool, $catchEntryBb, $nextCheck);
                        $checkBb = $nextCheck;
                        $builder->positionAtEnd($checkBb);
                    }
                }
            }

            if (null === $cachedCatchBb) {
                $builder->positionAtEnd($catchBodyBb);
                if (null !== $catchOp->arg3) {
                    $operand = $catchOp->block1->getOperand((int) $catchOp->arg3);
                    $caughtVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $pendingObj);
                    $jit->assignOperandForced($operand, $caughtVar);
                }
                if ($context->compilingGeneratorResume && null !== $catchOp->block1) {
                    $catchResume = $context->generatorCatchDispatchEntry[spl_object_id($catchOp->block1)] ?? null;
                    if (null !== $catchResume) {
                        $builder->branch($catchResume);
                        $nextCatch = $noMatchBb;
                        $builder->positionAtEnd($nextCatch);

                        continue;
                    }
                }
                $jit->compileCatchArmAtEntry($func, $catchOp->block1, $catchBodyBb, ...$args);
                $catchTail = $context->builder->getInsertBlock();
                $builder->positionAtEnd($catchTail);
                if (null !== $mergeBody && null === $catchTail->getTerminator()) {
                    $builder->branch($mergeBody);
                }
            } elseif (null !== $catchOp->arg3) {
                $builder->positionAtEnd($catchEntryBb);
                $operand = $catchOp->block1->getOperand((int) $catchOp->arg3);
                $caughtVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $pendingObj);
                $jit->assignOperandForced($operand, $caughtVar);
                $builder->branch($catchBodyBb);
            }

            $nextCatch = $noMatchBb;
            $builder->positionAtEnd($nextCatch);
        }

        $builder->branch($uncaught);
        $builder->positionAtEnd($uncaught);
        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $pendingObj);
        $builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);

        if (null !== $saved) {
            $builder->positionAtEnd($saved);
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

    private static function blockSuffix(TryCatchHandler $handler): string
    {
        return (string) spl_object_id($handler);
    }

    private static function appendBlock(Function_ $func, string $name): BasicBlock
    {
        return $func->appendBasicBlock($name);
    }
}

final class TryCatchHandler
{
    /** Remaining TYPE_CATCH/TYPE_FINALLY CFG opcodes to skip after beginTry (#2114). */
    public int $postTryOpcodesRemaining = 0;

    public bool $mergeEntryEmitted = false;

    public bool $mergeBodyCompiled = false;

    public ?BasicBlock $mergeEntryBb = null;

    public ?BasicBlock $dispatchBb = null;

    /**
     * @param list<array{op: OpCode, catchTypes: list<string>}> $catchArms
     */
    public function __construct(
        public Block $mergeBlock,
        public array $catchArms,
    ) {
    }
}
