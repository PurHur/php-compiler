<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for int↔string loose == JIT lowering (#4035).
 *
 * php-src: Zend/zend_operators.c — zend_compare_scalar int↔string path
 *
 * MCJIT execute remains gated by jit-runtime-probe (#98); this test guards IR lowering.
 *
 * @group llvm
 */
final class LooseScientificStringJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — loose == JIT compile test needs LLVM (#4035)');
        }
    }

    public function testLooseScientificStringCompareModuleVerify(): void
    {
        $runtime = new Runtime();
        foreach (
            [
                [
                    <<<'PHP'
<?php
echo (0 == '0e5') ? "1\n" : "0\n";
echo (0 == '0e123') ? "1\n" : "0\n";
echo (0 == '0') ? "1\n" : "0\n";
echo (1 == '1abc') ? "1\n" : "0\n";
PHP,
                    'issue3658_repro.php',
                ],
            ] as [$code, $filename]
        ) {
            $block = $runtime->parseAndCompile($code, $filename);
            $this->assertNotNull($block, $filename);
            $runtime->jitCompileBlock($block);
        }

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
