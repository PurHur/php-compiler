<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Guards merge-block reads register in Block::args (#3787). */
final class MatchArmAssignParseTest extends TestCase
{
    public function testMergeEchoBlockRegistersNamedReadOperands(): void
    {
        $code = <<<'PHP'
<?php
match (1) {
    1 => $x = 2,
    default => 0,
};
echo $x, "\n";
PHP;
        $runtime = new Runtime();
        $script = $runtime->parse($code, 'probe.php');
        $compiled = $runtime->compile($script);
        $echoBlock = $this->findEchoBlock($compiled);
        self::assertNotNull($echoBlock);
        $namedArgs = 0;
        foreach ($echoBlock->args as $op) {
            if ('x' === Block::resolveVariableName($op)) {
                ++$namedArgs;
            }
        }
        self::assertSame(1, $namedArgs);
    }

    private function findEchoBlock(Block $root): ?Block
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
                if (OpCode::TYPE_ECHO === $op->type) {
                    return $block;
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
}
