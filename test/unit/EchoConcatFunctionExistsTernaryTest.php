<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #14133 — echo concat must not drop LHS prefix across ?: with function_exists(). */
final class EchoConcatFunctionExistsTernaryTest extends TestCase
{
    public function testEchoConcatFunctionExistsTernaryPrintsPrefixAndSuffix(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/maintainer_gap_echo_concat_function_exists.php');
        self::assertIsString($code);
        $runtime = new Runtime();
        $script = $runtime->parse($code, 'probe.php');
        $compiled = $runtime->compile($script);
        ob_start();
        $runtime->run($compiled);
        $out = ob_get_clean();
        self::assertSame('strlen:y', $out);
    }

    public function testBranchAssignMustNotClobberPrefixConcatSlot(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/maintainer_gap_echo_concat_function_exists.php');
        self::assertIsString($code);
        $runtime = new Runtime();
        $script = $runtime->parse($code, 'probe.php');
        $compiled = $runtime->compile($script);
        $prefixSlot = $this->findPrefixConcatResultSlot($compiled);
        self::assertNotNull($prefixSlot);
        foreach ($this->branchAssignBlocks($compiled) as $branch) {
            foreach ($branch->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN !== $op->type) {
                    continue;
                }
                self::assertNotSame(
                    $prefixSlot,
                    (int) $op->arg1,
                    '?: branch assign result must not alias prefix concat slot (#14133)'
                );
                self::assertNotSame(
                    $prefixSlot,
                    (int) $op->arg2,
                    '?: branch assign dest must not alias prefix concat slot (#14133)'
                );
            }
        }
    }

    private function findPrefixConcatResultSlot(Block $root): ?int
    {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_CONCAT !== $op->type) {
                    continue;
                }
                if (OpCode::TYPE_JUMPIF === ($block->opCodes[$block->nOpCodes - 1]->type ?? null)) {
                    return (int) $op->arg1;
                }
            }
            foreach ($block->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return null;
    }

    /** @return list<Block> */
    private function branchAssignBlocks(Block $root): array
    {
        $found = [];
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            $hasAssign = false;
            $hasJump = false;
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_ASSIGN === $op->type) {
                    $hasAssign = true;
                }
                if (OpCode::TYPE_JUMP === $op->type) {
                    $hasJump = true;
                }
            }
            if ($hasAssign && $hasJump) {
                $found[] = $block;
            }
            foreach ($block->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return $found;
    }
}
