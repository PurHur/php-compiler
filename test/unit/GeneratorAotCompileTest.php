<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM verify for generator AOT lowering (#3115).
 *
 * @group llvm
 */
final class GeneratorAotCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — generator AOT compile test needs LLVM (#3115)');
        }
    }

    public function testStandaloneCompilePathAcceptsNestedGenerator(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen() {
    yield 1;
    yield 2;
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            'generator_aot_compile.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsGeneratorOpcodesInScriptScope($block));
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->assertArrayHasKey('gen', $context->generatorCreators);
        $this->addToAssertionCount(1);
    }

    public function testScriptScopeYieldDetectedForAotGuard(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php yield 1;', 'top_yield.php');
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsGeneratorOpcodesInScriptScope($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }
}
