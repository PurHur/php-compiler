<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #15183 — && phi must not clobber named locals passed to str_contains(). */
final class LogicalAndStrContainsNamedLocalTest extends TestCase
{
    public function testNamedLocalSurvivesExtensionLoadedStrContainsGuard(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/maintainer_gap_logical_and_str_contains_named.php');
        self::assertIsString($code);
        $runtime = new Runtime();
        $script = $runtime->parse($code, 'probe.php');
        $compiled = $runtime->compile($script);
        ob_start();
        $runtime->run($compiled);
        $out = ob_get_clean();
        self::assertStringContainsString('plain before=string', $out);
        self::assertStringContainsString('plain after=string', $out);
    }

    public function testPhiBoolCastMustNotClobberNamedHaystackSlot(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$out = 'hello';
if (!extension_loaded('curl') && str_contains($out, 'cURL')) {
}
PHP;
        $runtime = new Runtime();
        $script = $runtime->parse($code, 'probe.php');
        $compiled = $runtime->compile($script);
        $haystackSlot = $this->findNamedSlot($compiled, 'out');
        self::assertNotNull($haystackSlot);
        foreach ($this->allBlocks($compiled) as $block) {
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_CAST_BOOL !== $op->type && OpCode::TYPE_ASSIGN !== $op->type) {
                    continue;
                }
                if (OpCode::TYPE_CAST_BOOL === $op->type) {
                    self::assertNotSame(
                        $haystackSlot,
                        (int) $op->arg1,
                        'bool cast dest must not alias named haystack (#15183)'
                    );
                }
                if (OpCode::TYPE_ASSIGN === $op->type) {
                    self::assertNotSame(
                        $haystackSlot,
                        (int) $op->arg1,
                        'assign dest must not alias named haystack (#15183)'
                    );
                }
            }
        }
    }

    private function findNamedSlot(Block $root, string $name): ?int
    {
        foreach ($this->allBlocks($root) as $block) {
            $slot = $block->slotIndexForVariableName($name);
            if (null !== $slot) {
                return $slot;
            }
        }

        return null;
    }

    /** @return list<Block> */
    private function allBlocks(Block $root): array
    {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        $out = [];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            $out[] = $block;
            foreach ($block->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return $out;
    }
}
