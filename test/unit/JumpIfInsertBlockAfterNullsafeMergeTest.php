<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #15149: TYPE_JUMPIF after nullsafe block3 merge must recover insert block.
 */
final class JumpIfInsertBlockAfterNullsafeMergeTest extends TestCase
{
    public function testJumpIfLoweringEnsuresOpenInsertBlock(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertMatchesRegularExpression(
            '/case OpCode::TYPE_JUMPIF:\s+JIT\\\\BasicBlockHelper::ensureOpenInsertBlock\(\$this->context, \'jumpif_cont\'\);/',
            $source,
            'TYPE_JUMPIF must recover a null/cleared LLVM insert block before branchIf (#15149)'
        );
    }

    public function testPopScopeIgnoresEmptyStackDuringUnwind(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString(
            "if ([] === \$this->scopeStack) {\n            return;\n        }",
            $source
        );
    }

    public function testNullsafeMergeThenJumpIfCfgShape(): void
    {
        $code = <<<'PHP'
<?php
class Chain { public ?Chain $next = null; public int $v = 0; }
$root = new Chain();
$leaf = $root?->next?->v;
if ($leaf) {
    echo 'yes';
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nullsafe_then_if.php');
        $this->assertNotNull($block);
        $jumpIfAfterNullsafe = false;
        $seenNullsafeMerge = false;
        $this->walkCfg($block, static function (OpCode $op) use (&$jumpIfAfterNullsafe, &$seenNullsafeMerge): void {
            if (OpCode::TYPE_NULLSAFE === $op->type && null !== $op->block3) {
                $seenNullsafeMerge = true;
            }
            if ($seenNullsafeMerge && OpCode::TYPE_JUMPIF === $op->type) {
                $jumpIfAfterNullsafe = true;
            }
        });
        $this->assertTrue($seenNullsafeMerge, 'fixture must emit nullsafe with merge block');
        $this->assertTrue($jumpIfAfterNullsafe, 'fixture must emit JUMPIF after nullsafe merge');
    }

    /**
     * @param callable(OpCode): void $visit
     */
    private function walkCfg(Block $block, callable $visit): void
    {
        foreach ($block->opCodes as $op) {
            $visit($op);
            foreach (['block1', 'block2', 'block3'] as $child) {
                if (null !== $op->$child) {
                    $this->walkCfg($op->$child, $visit);
                }
            }
        }
    }
}
