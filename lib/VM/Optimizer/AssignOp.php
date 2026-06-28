<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM\Optimizer;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Optimizer;

class AssignOp extends Optimizer
{
    const CANDIDATE_OPS = [
        OpCode::TYPE_CONCAT,
        OpCode::TYPE_PLUS,
        OpCode::TYPE_MINUS,
        OpCode::TYPE_MUL,
        OpCode::TYPE_DIV,
        OpCode::TYPE_MODULO,
        OpCode::TYPE_POW,
        OpCode::TYPE_BITWISE_AND,
        OpCode::TYPE_BITWISE_OR,
        OpCode::TYPE_BITWISE_XOR,
        OpCode::TYPE_SHIFT_LEFT,
        OpCode::TYPE_SHIFT_RIGHT,
    ];

    public function optimize(Block $block, ?\SplObjectStorage $seen = null): void
    {
        $seen = $seen ?? new \SplObjectStorage();
        if ($seen->contains($block)) {
            return;
        }
        $seen->attach($block);
        $prior = null;
        $toRemove = [];
        foreach ($block->opCodes as $key => $op) {
            if ($op->type === OpCode::TYPE_ASSIGN && null !== $prior && in_array($prior->type, self::CANDIDATE_OPS, true)) {
                // replace
                $binaryOpResult = $block->getOperand($prior->arg1);
                if (count($binaryOpResult->usages) === 1) {
                    // We can safely replace it with an assign op
                    $binaryDest = $prior->arg1;
                    $prior->arg1 = $op->arg2;
                    // Compound assign ($x += 1): arg2 is the in-place lvalue — redirect both (#13083).
                    // Do not clobber additive/concat operands on ??/?: deferred RHS (#11801, #13104, #13105).
                    if (null === $prior->arg2 || (int) $prior->arg2 === (int) $binaryDest) {
                        $prior->arg2 = $op->arg2;
                    }
                    $assignResult = $block->getOperand($op->arg1);
                    if ((int) $op->arg3 === (int) $binaryDest || empty($assignResult->usages)) {
                        // Binary result was only copied into the assign dest; redirect makes assign dead (#11801).
                        $toRemove[] = $key;
                    } else {
                        // We still need the assign, since we're using the result
                        $op->arg2 = $op->arg1;
                    }
                }
            }
            $prior = $op;
            if (null !== $op->block1) {
                $this->optimize($op->block1, $seen);
            }
            if (null !== $op->block2) {
                $this->optimize($op->block2, $seen);
            }
        }

        if (! empty($toRemove)) {
            foreach ($toRemove as $key) {
                unset($block->opCodes[$key]);
            }
            $block->opCodes = array_values($block->opCodes);
            $block->nOpCodes = \count($block->opCodes);
        }
    }
}
