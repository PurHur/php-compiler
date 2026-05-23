<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\BasicBlock;
use PHPLLVM\Value\Function_;

/**
 * Helpers for LLVM CFG construction.
 *
 * {@see BasicBlock::insertBasicBlock()} inserts *before* the reference block, which
 * steals the function entry block if used on the current block. Always append
 * successor blocks with these helpers instead.
 */
final class BasicBlockHelper
{
    public static function parentFunction(Context $context): Function_
    {
        $parent = $context->builder->getInsertBlock()->getParent();
        if (!$parent instanceof Function_) {
            throw new \LogicException('Current basic block has no parent function');
        }

        return $parent;
    }

    public static function append(Context $context, string $name): BasicBlock
    {
        return self::parentFunction($context)->appendBasicBlock($name);
    }

    /**
     * Close CFG merge blocks left open by expression helpers (e.g. strval valueToString phi).
     */
    public static function sealOpenBlock(Context $context, BasicBlock $block): void
    {
        if (null !== $block->getTerminator()) {
            return;
        }
        $context->builder->positionAtEnd($block);
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    /**
     * Phi merge tails from JIT helpers (strval, concat, …) must not be left open in dead CFG paths.
     */
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

    /**
     * @param PHPLLVM\Value\Function_ $function
     */
    public static function sealFunction(Context $context, $function): void
    {
        if (0 === $function->countBasicBlocks()) {
            return;
        }
        $fnType = $function->typeOf();
        $isVoid = $fnType instanceof \PHPLLVM\Type\Function_
            && \PHPLLVM\Type::KIND_VOID === $fnType->getReturnType()->getKind();
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
