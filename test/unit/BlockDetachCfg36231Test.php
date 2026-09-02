<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class BlockDetachCfg36231Test extends TestCase
{
    public function testDetachCfgTreeNullsOrigOnNestedBlocks(): void
    {
        $runtime = new PHPCompiler\Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function f(): int {
        $g = 'a';
        $g = $g . 'b';
        return strlen($g);
    }
}
PHP;
        $block = $runtime->parseAndCompile($code, 'detach_cfg.php');
        $this->assertInstanceOf(PHPCompiler\Block::class, $block);
        $this->assertNotNull($block->orig);

        PHPCompiler\Block::detachCfgTree($block, false);
        $this->assertNull($block->orig);

        foreach ($block->opCodes as $op) {
            if (PHPCompiler\OpCode::TYPE_DECLARE_CLASS === $op->type && null !== $op->block1) {
                $this->assertNull($op->block1->orig, 'class body block orig must be released');
                foreach ($op->block1->opCodes as $memberOp) {
                    if (PHPCompiler\OpCode::TYPE_DECLARE_METHOD === $memberOp->type && null !== $memberOp->block1) {
                        $this->assertNull($memberOp->block1->orig, 'method body block orig must be released');
                    }
                }
            }
        }
    }

    public function testReleaseCfgAllowsSequentialCompileMemoryReuse(): void
    {
        $files = ['lib/Lint/Linter.php', 'lib/OpCode.php'];
        $without = $this->retainedAfterSequentialCompiles($files, false);
        $with = $this->retainedAfterSequentialCompiles($files, true);
        $this->assertLessThan(
            $without * 0.5,
            $with,
            'PHP_COMPILER_RELEASE_CFG_AFTER_COMPILE must drop sequential compile retention by >= 50% (#36231)'
        );
    }

    /**
     * @param list<string> $files
     */
    private function retainedAfterSequentialCompiles(array $files, bool $releaseCfg): int
    {
        if ($releaseCfg) {
            putenv('PHP_COMPILER_RELEASE_CFG_AFTER_COMPILE=1');
        } else {
            putenv('PHP_COMPILER_RELEASE_CFG_AFTER_COMPILE');
        }
        $runtime = new PHPCompiler\Runtime();
        $m0 = memory_get_usage(false);
        foreach ($files as $file) {
            $path = dirname(__DIR__, 2).'/'.$file;
            $block = $runtime->parseAndCompile((string) file_get_contents($path), $file);
            $this->assertInstanceOf(PHPCompiler\Block::class, $block);
            unset($block);
            gc_collect_cycles();
        }

        return memory_get_usage(false) - $m0;
    }
}
