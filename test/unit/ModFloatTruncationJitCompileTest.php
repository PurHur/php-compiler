<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for % with float and numeric-string operands (#5082, #4730).
 *
 * php-src: Zend/zend_operators.c mod_function()
 *
 * @group llvm
 */
final class ModFloatTruncationJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — mod float JIT compile test needs LLVM (#5082)');
        }
    }

    public function testModFloatTruncationModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
echo 5.7 % 2.2, "\n";
echo 5.9 % 2, "\n";
echo -5.7 % 2.2, "\n";
echo '7' % '3', "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'mod_float_truncation_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
