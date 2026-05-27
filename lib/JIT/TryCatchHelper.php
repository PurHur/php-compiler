<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
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
            $mergeBb = BasicBlockHelper::append($context, 'try_merge');
        }
        if (!$handler->mergeBodyCompiled) {
            $jit->compileIncludedAtEntry($func, $handler->mergeBlock, $mergeBb);
            $handler->mergeBodyCompiled = true;
        }
        if (null === $handler->dispatchBb) {
            $handler->dispatchBb = self::buildDispatch($jit, $func, $context, $handler, $args);
        }
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
        if (null === $handler->dispatchBb) {
            $handler->dispatchBb = self::buildDispatch($jit, $func, $context, $handler, $args);
        }

        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $entryBb = BasicBlockHelper::append($context, 'try_merge_entry');
        $handler->mergeEntryBb = $entryBb;
        $builder->positionAtEnd($entryBb);
        $hasPending = $builder->call($context->lookupFunction('phpc_jit_has_throw_pending'));
        $i32 = $context->getTypeFromString('int32');
        $hasBool = $builder->icmp(
            Builder::INT_NE,
            $hasPending,
            $i32->constInt(0, false)
        );
        $builder->branchIf($hasBool, $handler->dispatchBb, $mergeBb);
        if (null !== $saved) {
            $builder->positionAtEnd($saved);
        }
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
            $builder->positionAtEnd($throwBlock);
            $context->freeDeadVariables($func, $throwBlock, $block);
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);

            return;
        }
        if (null === $handler->dispatchBb) {
            $handler->dispatchBb = self::buildDispatch($jit, $func, $context, $handler, []);
        }

        $thrown = $context->getVariableFromOp($block->getOperand($op->arg1));
        $obj = $context->helper->loadValue($thrown);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $thrown);
            $obj = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        }

        $builder = $context->builder;
        $throwBlock = $builder->getInsertBlock();
        $builder->positionAtEnd($throwBlock);
        $builder->call($context->lookupFunction('phpc_jit_set_throw_pending'), $obj);
        $builder->branch($handler->dispatchBb);
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
        $dispatch = BasicBlockHelper::append($context, 'try_catch_dispatch');
        $builder = $context->builder;
        $saved = $builder->getInsertBlock();
        $builder->positionAtEnd($dispatch);

        $pendingObj = $builder->call($context->lookupFunction('phpc_jit_take_throw_pending'));
        $mergeBody = $context->scope->blockStorage[$handler->mergeBlock] ?? null;

        $uncaught = BasicBlockHelper::append($context, 'try_uncaught');
        $nextCatch = $dispatch;
        $singleArm = 1 === count($handler->catchArms);

        foreach ($handler->catchArms as $arm) {
            $catchOp = $arm['op'];
            $types = $arm['catchTypes'];
            $matchBb = BasicBlockHelper::append($context, 'try_catch_match');
            $noMatchBb = BasicBlockHelper::append($context, 'try_catch_nomatch');

            $builder->positionAtEnd($nextCatch);
            if ([] === $types || $singleArm) {
                $builder->branch($matchBb);
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
                        $builder->branchIf($isBool, $matchBb, $noMatchBb);
                    } else {
                        $nextCheck = BasicBlockHelper::append($context, 'try_catch_type_next');
                        $builder->branchIf($isBool, $matchBb, $nextCheck);
                        $checkBb = $nextCheck;
                        $builder->positionAtEnd($checkBb);
                    }
                }
            }

            $builder->positionAtEnd($matchBb);
            if (null !== $catchOp->arg3) {
                $operand = $catchOp->block1->getOperand((int) $catchOp->arg3);
                $caughtVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $pendingObj);
                $jit->assignOperandForced($operand, $caughtVar);
            }
            $jit->compileIncludedAtEntry($func, $catchOp->block1, $matchBb);
            $catchTail = $context->builder->getInsertBlock();
            $builder->positionAtEnd($catchTail);
            if (null !== $mergeBody && null === $catchTail->getTerminator()) {
                $builder->branch($mergeBody);
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

    public static function popHandler(Context $context): void
    {
        if ([] === $context->tryCatch->handlerStack) {
            return;
        }
        $handler = array_pop($context->tryCatch->handlerStack);
        unset($context->tryCatch->mergeHandlers[spl_object_id($handler->mergeBlock)]);
    }
}

final class TryCatchHandler
{
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
