<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Block;
use PHPCfg\Func;
use PHPCfg\LivenessDetector;
use PHPCfg\Operand;
use PHPCfg\Op;
use PHPCompiler\Cfg\OpSubBlockAccess;

/**
 * Skip liveness for declaration-only functions (interfaces, abstract stubs) with no CFG.
 * Tolerate empty CFG blocks from match comma-condition fallthrough (#3717).
 */
final class NullSafeLivenessDetector extends LivenessDetector
{
    protected function detectFunc(Func $func): void
    {
        if (!$func->cfg instanceof Block) {
            return;
        }

        $startBlock = $func->cfg;
        $seen = new \SplObjectStorage();
        $queue = [$startBlock];
        $endBlocks = [];
        $variables = new \SplObjectStorage();
        while (!empty($queue)) {
            $block = array_pop($queue);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            $this->collectVariables($block, $variables);
            $lastOp = end($block->children);
            if (false === $lastOp) {
                continue;
            }

            if ($lastOp instanceof Op\Terminal\Return_) {
                $endBlocks[] = $block;
            }
            foreach ($lastOp->getSubBlocks() as $name) {
                $tmp = OpSubBlockAccess::propertyValue($lastOp, $name);
                if (is_array($tmp)) {
                    foreach ($tmp as $obj) {
                        $queue[] = $obj;
                    }
                } elseif ($tmp instanceof Block) {
                    $queue[] = $tmp;
                } else {
                    throw new \LogicException('Found non-block in subblocks');
                }
            }
        }

        $this->hoist($startBlock, $variables);
        foreach ($endBlocks as $endBlock) {
            $this->computeDeath($endBlock, $variables);
        }
    }
}
