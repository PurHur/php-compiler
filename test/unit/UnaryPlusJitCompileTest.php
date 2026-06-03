<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for unary + JIT lowering (#4820).
 *
 * php-src: Zend/zend_operators.c
 *
 * @group llvm
 */
final class UnaryPlusJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — unary + JIT compile test needs LLVM (#4820)');
        }
    }

    public function testUnaryPlusModuleVerify(): void
    {
        $runtime = new Runtime();
        foreach (
            [
                [
                    <<<'PHP'
<?php
var_export(+'0x10');
echo "\n";
var_export(+'42');
echo "\n";
PHP,
                    'unary_plus_non_numeric.php',
                ],
                [
                    file_get_contents($this->repoRoot.'/test/repro-maintainer/unary_plus_non_numeric.php'),
                    'unary_plus_non_numeric.php',
                ],
            ] as [$code, $filename]
        ) {
            $this->assertNotFalse($code);
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
