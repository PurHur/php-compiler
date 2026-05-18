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
}
