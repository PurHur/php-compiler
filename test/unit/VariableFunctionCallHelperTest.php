<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\VariableFunctionCallHelper;

final class VariableFunctionCallHelperTest extends TestCase
{
    public function testDispatchCandidatesUsesHintsNotAllBuiltins(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function myfn(): int {
    return 1;
}
$name = $_GET['op'] ?? 'strlen';
echo $name('x');
PHP;
        $block = $runtime->parseAndCompile($code, 'vf_hints.php');
        $ctx = $runtime->loadJit()->context;
        $hints = array_merge(
            VariableFunctionCallHelper::hintedCalleeNames($block, null),
            VariableFunctionCallHelper::coalesceBranchLiteralHints($block),
            VariableFunctionCallHelper::funDefNamesInCompilationUnit($block)
        );
        $candidates = VariableFunctionCallHelper::dispatchCandidates($ctx, $hints);
        $this->assertArrayHasKey('strlen', $candidates);
        $this->assertLessThan(
            16,
            count($candidates),
            'dynamic $fn() must not lower a dispatch chain over every registered native builtin'
        );
        $this->assertContains('myfn', $hints);
    }
}
