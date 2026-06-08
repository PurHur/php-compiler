<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Match expressions no longer force whole-script VM fallback in bin/jit.php (#4623).
 */
final class MatchJitVmLoweringTest extends TestCase
{
    public function testScriptScopeMatchDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
echo match (2) {
    1 => 'a',
    2 => 'b',
    default => 'c',
}, "\n";
PHP
            ,
            'match_vm_lower.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsMatchExpressionOpcodesInScriptScope($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }
}
