<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** #13092: PackEngine nested JIT requires valid ARRAY_DIM_FETCH arg2. */
final class PackEngineArrayDimFetchCompileTest extends TestCase
{
    public function testPackEngineHasNoArrayDimFetchWithNullContainer(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $root = $runtime->parseAndCompile(
            (string) file_get_contents(__DIR__.'/../../../ext/standard/PackEngine.php'),
            'PackEngine.php'
        );
        $this->assertNotNull($root);
        $bad = [];
        $this->walkBlocks($root, static function (Block $block, int $opIndex, OpCode $op) use (&$bad): void {
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH !== $op->type
                && OpCode::TYPE_ARRAY_DIM_FETCH_WRITE !== $op->type
            ) {
                return;
            }
            if (null === $op->arg2) {
                $fn = $block->func?->name ?? '?';
                $bad[] = sprintf('%s op%d', $fn, $opIndex);
            }
        });
        $this->assertSame([], $bad, 'ARRAY_DIM_FETCH must always set container slot (arg2)');
    }

    /**
     * @param callable(Block, int, OpCode): void $visit
     */
    private function walkBlocks(Block $root, callable $visit): void
    {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $i => $op) {
                $visit($block, $i, $op);
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
            foreach ($block->blocks as $sub) {
                if ($sub instanceof Block) {
                    $stack[] = $sub;
                }
            }
        }
    }
}
