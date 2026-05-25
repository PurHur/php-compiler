<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Opcode shape for 007-ThrowsWeb try/catch (#2157). */
final class ThrowsWebCompileOpcodesTest extends TestCase
{
    public function testCatchTypesIncludeValidationError(): void
    {
        $path = dirname(__DIR__, 2).'/examples/007-ThrowsWeb/example.php';
        if (!is_file($path)) {
            $this->markTestSkipped('examples/007-ThrowsWeb missing');
        }
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'example.php');
        $this->assertNotNull($block);
        $found = false;
        foreach ($this->allBlocks($block) as $cfgBlock) {
            foreach ($cfgBlock->opCodes as $op) {
                if (OpCode::TYPE_CATCH !== $op->type) {
                    continue;
                }
                $found = true;
                $this->assertNotNull($op->catchTypes);
                $this->assertStringContainsString('validationerror', strtolower((string) $op->catchTypes));
            }
        }
        $this->assertTrue($found, 'expected TYPE_CATCH opcode in 007 CFG');
    }

    /**
     * @return list<Block>
     */
    private function allBlocks(Block $root): array
    {
        $seen = [];
        $out = [];
        $stack = [$root];
        while ([] !== $stack) {
            $b = array_pop($stack);
            $id = spl_object_id($b);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $b;
            foreach ($b->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $child) {
                    if ($child instanceof Block) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return $out;
    }
}
