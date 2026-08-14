<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Type;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

final class BasicBlockHelper
{
    /** LLVMTypeOf(function) is a pointer-to-signature, not Function_ (#1492 bootstrap). */
    public static function llvmFunctionSignatureType(Value $func): ?\PHPLLVM\Type\Function_
    {
        $ty = $func->typeOf();
        if ($ty instanceof \PHPLLVM\Type\Function_) {
            return $ty;
        }
        if ($ty instanceof \PHPLLVM\Type\Pointer) {
            $el = $ty->getElementType();
            if ($el instanceof \PHPLLVM\Type\Function_) {
                return $el;
            }
        }

        return null;
    }

    public static function isVoidLlvmFunctionValue(Value $func): bool
    {
        $sig = self::llvmFunctionSignatureType($func);
        if (null === $sig) {
            return false;
        }

        return Type::KIND_VOID === $sig->getReturnType()->getKind();
    }

    public static function tryGetInsertBlock(Context $context): ?BasicBlock
    {
        $ref = $context->llvm->lib->LLVMGetInsertBlock($context->builder->builder);
        if (null === $ref) {
            return null;
        }

        return $context->llvm->factory->basicBlock($context->context, $ref);
    }

    public static function parentFunction(Context $context): Function_
    {
        $insert = self::tryGetInsertBlock($context);
        $insertParent = null;
        if (null !== $insert) {
            $parent = $insert->getParent();
            if ($parent instanceof Function_) {
                $insertParent = $parent;
            }
        }
        // Prefer in-flight lowering owner when insert is parked elsewhere (#31101).
        // Helpers that emit their own LLVM fn must set loweringLlvmFunction to that fn
        // (see HashTableReplaceRecursiveLlvm) so this does not steal their appends.
        if (
            $context->loweringLlvmFunction instanceof Function_
            && !NestedJitCompileScope::isActive()
            && (
                !$insertParent instanceof Function_
                || !TryCatchHelper::sameLlvmFunction($insertParent, $context->loweringLlvmFunction)
            )
        ) {
            return $context->loweringLlvmFunction;
        }
        if ($insertParent instanceof Function_) {
            return $insertParent;
        }
        if ('' !== $context->activeFunction) {
            $active = $context->functions[$context->activeFunction]
                ?? $context->functionScope[$context->activeFunction]
                ?? null;
            if ($active instanceof Function_) {
                return $active;
            }
        }
        if ($context->main instanceof Function_) {
            return $context->main;
        }
        throw new \LogicException('Current basic block has no parent function');
    }

    public static function append(Context $context, string $name): BasicBlock
    {
        return self::parentFunction($context)->appendBasicBlock($name);
    }

    public static function branchToFreshContinue(Context $context, string $name): void
    {
        $tail = $context->builder->getInsertBlock();
        if (null === $tail || null !== $tail->getTerminator()) {
            return;
        }
        $continue = self::append($context, $name);
        $after = self::append($context, $name.'_after');
        $context->builder->branch($continue);
        $context->builder->positionAtEnd($continue);
        $context->builder->branch($after);
        $context->builder->positionAtEnd($after);
    }

    /**
     * Continue emission in an open block; if the insert block is already sealed, use a fresh one.
     *
     * Never append instructions after an existing terminator (invalid IR).
     * When insert is cleared mid-function (NestedJIT / const-string builder swap), prefer the
     * function's last open BB over an orphaned label with no preds (Runtime::parse M5 — #26756).
     * Sealed insert still appends a fresh BB — do not jump to an unrelated open block (#26756 cold-build).
     */
    public static function ensureOpenInsertBlock(Context $context, string $label): void
    {
        $insert = self::tryGetInsertBlock($context);
        if (null === $insert) {
            $fn = self::parentFunction($context);
            $open = self::lastOpenBasicBlock($fn);
            if (null !== $open) {
                $context->builder->positionAtEnd($open);

                return;
            }
            $next = $fn->appendBasicBlock($label);
            $context->builder->positionAtEnd($next);

            return;
        }
        if (null === $insert->getTerminator()) {
            return;
        }
        // Sealed insert: always append a fresh BB. Jumping to an unrelated open block
        // mid-lower causes terminator-in-middle on cold-build (bisect abcfd80e6 / #26756).
        $next = self::append($context, $label);
        $context->builder->positionAtEnd($next);
    }

    /**
     * Last basic block in $fn that still lacks a terminator (may be mid-lower open tail).
     */
    public static function lastOpenBasicBlock(Function_ $fn): ?BasicBlock
    {
        $open = null;
        if (0 === $fn->countBasicBlocks()) {
            return null;
        }
        $block = $fn->getFirstBasicBlock();
        while (null !== $block) {
            if (null === $block->getTerminator()) {
                $open = $block;
            }
            $block = $block->getNext();
        }

        return $open;
    }

    public static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null === $block) {
            $context->builder->clearInsertionPosition();

            return;
        }
        if (null === $block->getTerminator()) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $func = $block->getParent();
        if ($func instanceof Function_) {
            $next = $func->appendBasicBlock('restore_insert_cont');
            $context->builder->positionAtEnd($next);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    public static function entryAlloca(Context $context, Type $type): Value
    {
        $restore = self::tryGetInsertBlock($context);
        if (null !== $restore) {
            $parent = $restore->getParent();
            if (!$parent instanceof Function_) {
                throw new \LogicException('entryAlloca insert block has no parent function');
            }
            $fn = $parent;
        } else {
            $fn = self::parentFunction($context);
        }

        return self::entryAllocaForFunction($context, $fn, $type);
    }

    public static function entryAllocaForFunction(Context $context, Function_ $fn, Type $type): Value
    {
        $entry = $fn->countBasicBlocks() > 0
            ? $fn->getEntryBasicBlock()
            : $fn->appendBasicBlock('entry');
        $restore = self::tryGetInsertBlock($context);
        try {
            $first = $entry->getFirstInstruction();
            $context->builder->position($entry, $first);
        } catch (\Throwable) {
            $context->builder->positionAtEnd($entry);
        }
        $slot = $context->builder->alloca($type);
        if (null !== $restore) {
            // Never positionBefore(terminator) / never clear when a restore BB exists —
            // callers keep emitting (fromLiteral string init, value-box writes). Sealed
            // restore → append open cont via restoreInsertBlock (Runtime::parse M5 — #26756).
            self::restoreInsertBlock($context, $restore);
        }
        // restore === null: leave insert after the alloca in entry so callers can emit.

        return $slot;
    }

    /**
     * Store into an entry alloca from the function entry block (after leading allocas).
     *
     * CONCAT KIND_VALUE→alloca promotion must not emit the seed store in a loop body —
     * that re-initializes the local every iteration (#22845 MiniWebApp htmlspecialchars).
     */
    public static function storeAtFunctionEntry(Context $context, Function_ $fn, Value $value, Value $slot): void
    {
        $entry = $fn->countBasicBlocks() > 0
            ? $fn->getEntryBasicBlock()
            : $fn->appendBasicBlock('entry');
        $restore = self::tryGetInsertBlock($context);
        try {
            $inst = $entry->getFirstInstruction();
            while (null !== $inst && $inst->isAAllocaInst()) {
                $inst = $inst->getNext();
            }
            if (null !== $inst) {
                // Insert before first non-alloca (may be the terminator) — never after it.
                $context->builder->position($entry, $inst);
            } else {
                $terminator = $entry->getTerminator();
                if (null !== $terminator) {
                    $context->builder->positionBefore($terminator);
                } else {
                    $context->builder->positionAtEnd($entry);
                }
            }
        } catch (\Throwable) {
            $terminator = $entry->getTerminator();
            if (null !== $terminator) {
                $context->builder->positionBefore($terminator);
            } else {
                $context->builder->positionAtEnd($entry);
            }
        }
        $context->builder->store($value, $slot);
        // Same sealed-block rule as entryAllocaForFunction (#26756): do not splice before a
        // terminator or clear insert when the caller will keep emitting.
        self::restoreInsertBlock($context, $restore);
    }

    public static function sealOpenBlock(Context $context, BasicBlock $block): void
    {
        if (null !== $block->getTerminator()) {
            return;
        }
        $context->builder->positionAtEnd($block);
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    public static function sealPhiMergeBlocks(Context $context, BasicBlock $block): void
    {
        if (null !== $block->getTerminator()) {
            return;
        }
        $name = $block->getName();
        if (!str_contains($name, 'strval_value_done')
            && !str_contains($name, 'strval_value_rest')
            && !str_contains($name, 'strval_bool_end')
            && !str_contains($name, 'concat_done')
            && !str_contains($name, 'concat_rest')) {
            return;
        }
        self::sealOpenBlock($context, $block);
    }

    public static function sealFunction(Context $context, $function): void
    {
        if (0 === $function->countBasicBlocks()) {
            return;
        }
        $isVoid = self::isVoidLlvmFunctionValue($function);
        $block = $function->getFirstBasicBlock();
        while (null !== $block) {
            self::sealPhiMergeBlocks($context, $block);
            if (null === $block->getTerminator()) {
                $context->builder->positionAtEnd($block);
                if ($isVoid) {
                    $context->builder->returnVoid();
                } else {
                    self::sealOpenBlock($context, $block);
                }
            }
            $block = $block->getPrevious();
        }
    }
}
