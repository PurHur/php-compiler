<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Closure use (&$fn) self-recursion must not clobber capture slots (#17089).
 */
final class ClosureRecursiveUseCompileTest extends TestCase
{
    public function testRecursiveUseByRefFibonacciDoesNotClobberCaptureSlot(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
$fib = function (int $n) use (&$fib) {
    return $n <= 1 ? $n : $fib($n - 1) + $fib($n - 2);
};
echo $fib(5), "\n";
PHP,
            'closure_recursive_fib.php'
        );
        $this->assertNotNull($block);
        $closureBlock = $this->findClosureBodyBlock($block);
        $this->assertNotNull($closureBlock);
        $fibSlot = $closureBlock->slotIndexForVariableName('fib');
        $this->assertNotNull($fibSlot);
        foreach ($this->walkBlocks($closureBlock) as $body) {
            foreach ($body->opCodes as $op) {
                if (OpCode::TYPE_MINUS !== $op->type) {
                    continue;
                }
                $this->assertNotSame(
                    $fibSlot,
                    (int) $op->arg1,
                    'arithmetic result must not overwrite closure capture slot'
                );
            }
        }
    }

    public function testRecursiveUseByRefFibonacciRunsOnVm(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
$fib = function (int $n) use (&$fib) {
    return $n <= 1 ? $n : $fib($n - 1) + $fib($n - 2);
};
echo $fib(5), "\n";
PHP,
            'closure_recursive_fib_runtime.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("5\n", ob_get_clean());
    }

    private function findClosureBodyBlock(Block $block): ?Block
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLOSURE === $op->type) {
                return $op->block1;
            }
            foreach ([$op->block1, $op->block2] as $sub) {
                if ($sub instanceof Block) {
                    $found = $this->findClosureBodyBlock($sub);
                    if (null !== $found) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<Block>
     */
    private function walkBlocks(Block $block): array
    {
        $out = [$block];
        foreach ($block->opCodes as $op) {
            foreach ([$op->block1, $op->block2] as $sub) {
                if ($sub instanceof Block) {
                    $out = array_merge($out, $this->walkBlocks($sub));
                }
            }
        }

        return $out;
    }
}
