<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM;

/**
 * Sub-block / try-catch-finally entry lowering and generator yield-from resume (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileSubBlock} through
 * {@code compileGeneratorResumePrefix} so the hub shrinks toward gen-0
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c try/catch/finally and generator yield-from
 * (zend_generators.c) — move-only Concern extract; no new C ABI and no
 * opcode/IR shape change.
 */
trait SubBlockCatchFinallyAndGeneratorResume
{
    public function compileSubBlock(
        PHPLLVM\Value $func,
        Block $block,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, null, 0, false, ...$args);
    }

    /**
     * Try-body lowering after catch dispatch may have seeded blockStorage (#4041 / #25841).
     *
     * @param list<Variable> $args
     */
    public function compileTrySubBlock(
        PHPLLVM\Value $func,
        Block $block,
        array $args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, null, 0, true, ...$args);
    }

    /**
     * Lower a ?? / ??= arm at a pre-built entry BB after the test BB is sealed (#32880).
     *
     * Compiling arms before {@see Builder::branchIf} leaves the test BB open; NestedJIT /
     * {@see JIT\BasicBlockHelper::ensureOpenInsertBlock} can resume into it (often
     * {@code prop_value_done} after {@code new}) and plant a second terminator.
     *
     * @param list<Variable> $args
     */
    public function compileSubBlockAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true, ...$args);
    }

    /**
     * Inline an included compilation unit at a dedicated entry block (issue #568 / MiniWebApp templates).
     */
    public function compileIncludedAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        ?int $opcodeLimit = null
    ): PHPLLVM\BasicBlock {
        $limit = $opcodeLimit ?? $this->includedAtEntryOpcodeLimit($block);

        $this->context->inlineIncludeExitBlock = null;
        $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true);
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'included_at_entry_cont');

        return $exit;
    }

    /**
     * Lower a catch arm at {@see TryCatchHelper::buildDispatch} match entry (#4041).
     *
     * Catch CFG blocks may already sit in blockStorage from an earlier partial compile;
     * force re-lowering at the dispatch match BB and skip the trailing merge JUMP
     * (TryCatchHelper branches to merge after the arm body).
     *
     * @param list<Variable> $args
     */
    public function compileCatchArmAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        $this->context->inlineIncludeExitBlock = null;
        // Catch arms fall through to try-merge; suppress the void-main trailing
        // returnVoid() that would skip AFTER (#23641).
        $savedSynthetic = $block->syntheticCfgBranch;
        $block->syntheticCfgBranch = true;
        try {
            $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true, ...$args);
        } finally {
            $block->syntheticCfgBranch = $savedSynthetic;
        }
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }

        return $exit;
    }

    /**
     * Lower a finally CFG arm at entry (#4246).
     *
     * finallyBbFor pre-seeds blockStorage[finally] before calling here; without
     * allowRecompile the body is skipped and finally is an empty fall-through (#24105).
     * syntheticCfgBranch suppresses void-main returnVoid so the epilogue edge remains.
     *
     * @param list<Variable> $args
     */
    public function compileFinallyAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        $this->context->inlineIncludeExitBlock = null;
        // Mirror compileCatchArmAtEntry (#23641 / #24105): re-lower at the pinned
        // finally BB and keep the tail open for TryCatchHelper's epilogue branch.
        $savedSynthetic = $block->syntheticCfgBranch;
        $block->syntheticCfgBranch = true;
        try {
            $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock, 0, true, ...$args);
        } finally {
            $block->syntheticCfgBranch = $savedSynthetic;
        }
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }
        JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'finally_at_entry_cont');

        return $exit;
    }

    /** Resume LLVM return after return-through-finally (#4246). */
    public function emitPendingReturnResume(PHPLLVM\Value $func): void
    {
        JIT\Builtin\JitReturnPending::registerDeclarations($this->context);
        JIT\Builtin\JitReturnPending::ensureLinked($this->context);
        $builder = $this->context->builder;
        $i32 = $this->context->getTypeFromString('int32');
        $isVoid = $builder->call($this->context->lookupFunction('phpc_jit_return_pending_is_void'));
        $isVoidBool = $builder->icmp(PHPLLVM\Builder::INT_NE, $isVoid, $i32->constInt(0, false));
        $voidBb = $func->appendBasicBlock('pending_return_void');
        $valueBb = $func->appendBasicBlock('pending_return_value');
        $builder->branchIf($isVoidBool, $voidBb, $valueBb);
        $builder->positionAtEnd($voidBb);
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();
        } else {
            $builder->returnValue($this->defaultLlvmReturnValue($func));
        }
        $builder->positionAtEnd($valueBb);
        $valuePtr = $builder->call($this->context->lookupFunction('phpc_jit_take_return_pending'));
        if ($this->isVoidLlvmFunction($func)) {
            $builder->returnVoid();
        } else {
            $expected = null;
            if (null !== $this->context->activeFunction) {
                $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            }
            // Prefer the LLVM return type: untyped PHP functions return __value__ even when
            // functionReturnType is unset — defaulting to readLong corrupted null/bool/float
            // pending returns after finally (#24105).
            $llvmRet = null;
            $sig = JIT\BasicBlockHelper::llvmFunctionSignatureType($func);
            if (null !== $sig) {
                $llvmRet = $this->context->getStringFromType($sig->getReturnType());
            }
            if ('__value__' === $llvmRet || '__value__' === $expected) {
                $retval = $builder->load($valuePtr);
            } else {
                $retval = $this->loadPendingReturnValue($valuePtr, $expected ?? $llvmRet);
                $retval = $this->alignRetvalToLlvmFnReturn($retval, $func);
            }
            $builder->returnValue($retval);
        }
    }

    private function loadPendingReturnValue(PHPLLVM\Value $valuePtr, ?string $expectedReturn): PHPLLVM\Value
    {
        if ('__value__' === $expectedReturn) {
            return $this->context->builder->load($valuePtr);
        }
        $read = match ($expectedReturn) {
            'string', '__string__*' => '__value__readString',
            'double' => '__value__readDouble',
            'bool', 'int1' => '__value__readLong',
            '__object__*' => '__value__readObject',
            '__hashtable__*' => '__value__readHashtable',
            default => '__value__readLong',
        };
        $loaded = $this->context->builder->call($this->context->lookupFunction($read), $valuePtr);
        if ('bool' === $expectedReturn || 'int1' === $expectedReturn) {
            return $this->context->builder->truncOrBitCast(
                $loaded,
                $this->context->getTypeFromString('int1')
            );
        }

        return $loaded;
    }

    /**
     * Opcode limit for compileIncludedAtEntry: skip redundant try-entry JUMP only (#2084).
     */
    private function includedAtEntryOpcodeLimit(Block $block): int
    {
        $limit = $block->nOpCodes;
        while ($limit > 0 && !isset($block->opCodes[$limit - 1])) {
            --$limit;
        }
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            $jump = $block->opCodes[$limit - 1];
            if (null !== $jump->block1 && $this->isRedundantTryEntryJump($block, $jump->block1)) {
                --$limit;
            }
        }

        return $limit;
    }

    /**
     * php-cfg TryCatch emits a Stmt_Jump into the try body; TYPE_TRY already enters it (#2084).
     */
    private function isRedundantTryEntryJump(Block $block, Block $target): bool
    {
        for ($i = $block->nOpCodes - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_CATCH === $op->type || OpCode::TYPE_FINALLY === $op->type) {
                continue;
            }
            if (OpCode::TYPE_TRY === $op->type) {
                return $op->block1 === $target;
            }

            break;
        }

        return false;
    }

    /**
     * Compile opcodes before yield from to evaluate the container (e.g. inner() call).
     *
     * @return JIT\Variable container variable for yield from
     */
    public function compileGeneratorYieldFromSetup(
        \PHPLLVM\Value\Function_ $func,
        Block $block,
        \PHPLLVM\BasicBlock $entryBlock,
        OpCode $yieldFromOp,
        ?string $innerResumeName = null,
        int $prefixStart = 0
    ): JIT\Variable {
        $yfIdx = null;
        foreach ($block->opCodes as $i => $op) {
            if ($op === $yieldFromOp) {
                $yfIdx = $i;
                break;
            }
        }
        if (null === $yfIdx) {
            throw new \LogicException('yield from opcode not found in generator block');
        }
        if (
            $this->generatorYieldFromPrefixNeedsCompile($block, $yfIdx, $innerResumeName, $prefixStart)
        ) {
            $savedStorage = $this->context->scope->blockStorage;
            $this->context->scope->blockStorage = new \SplObjectStorage();
            $exit = $this->compileGeneratorResumePrefix($func, $block, $prefixStart, $yfIdx, $entryBlock);
            $this->context->builder->positionAtEnd($exit);
            $this->context->scope->blockStorage = $savedStorage;
        }
        if (null === $yieldFromOp->arg2) {
            throw new \LogicException('yield from missing container operand');
        }

        return $this->context->getVariableFromOp($block->getOperand($yieldFromOp->arg2));
    }

    /**
     * Compile prefix opcodes before yield from when the container is not yet materialized (#3074).
     * Includes inline array literals (INIT_ARRAY) and dynamic containers (call/assign).
     */
    private function generatorYieldFromPrefixNeedsCompile(
        Block $block,
        int $yfIdx,
        ?string $innerResumeName,
        int $prefixStart = 0
    ): bool {
        if (null !== $innerResumeName) {
            return true;
        }
        if (
            $yfIdx <= $prefixStart
            || !JIT\GeneratorHelper::prefixSegmentSafeForYieldFromInit($block, $prefixStart, $yfIdx)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Compile opcodes in [$startIndex, $limit) for generator resume prefix segments (#3074).
     */
    public function compileGeneratorResumePrefix(
        PHPLLVM\Value\Function_ $func,
        Block $block,
        int $startIndex,
        int $limit,
        PHPLLVM\BasicBlock $entryBlock
    ): PHPLLVM\BasicBlock {
        return $this->compileBlockInternal(
            $func,
            $block,
            $limit,
            $entryBlock,
            $startIndex,
            true
        );
    }
}
