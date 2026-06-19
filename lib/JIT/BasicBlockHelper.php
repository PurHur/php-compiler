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
        if (null !== $insert) {
            $parent = $insert->getParent();
            if ($parent instanceof Function_) {
                return $parent;
            }
        }
        if ('' !== $context->activeFunction && isset($context->functions[$context->activeFunction])) {
            $fn = $context->functions[$context->activeFunction];
            if ($fn instanceof Function_) {
                return $fn;
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
     */
    public static function ensureOpenInsertBlock(Context $context, string $label): void
    {
        $insert = self::tryGetInsertBlock($context);
        if (null === $insert) {
            $fn = self::parentFunction($context);
            $next = $fn->appendBasicBlock($label);
            $context->builder->positionAtEnd($next);

            return;
        }
        if (null === $insert->getTerminator()) {
            return;
        }
        $next = self::append($context, $label);
        $context->builder->positionAtEnd($next);
    }

    public static function restoreInsertBlock(Context $context, BasicBlock $block): void
    {
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
        $entry = $fn->getEntryBasicBlock();
        $restore = self::tryGetInsertBlock($context);
        try {
            $first = $entry->getFirstInstruction();
            $context->builder->position($entry, $first);
        } catch (\Throwable) {
            $context->builder->positionAtEnd($entry);
        }
        $slot = $context->builder->alloca($type);
        if (null !== $restore) {
            $terminator = $restore->getTerminator();
            if (null !== $terminator) {
                // Do not position after a terminator: it creates invalid IR ("terminator in the middle").
                $context->builder->positionBefore($terminator);
            } else {
                $context->builder->positionAtEnd($restore);
            }
        }

        return $slot;
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
