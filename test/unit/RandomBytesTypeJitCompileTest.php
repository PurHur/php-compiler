<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for random_bytes() Z_PARAM_LONG lowering (#4626).
 *
 * php-src: ext/standard/random.c — PHP_FUNCTION(random_bytes)
 *
 * @group llvm
 */
final class RandomBytesTypeJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — random_bytes type JIT compile test needs LLVM');
        }
    }

    public function testNumericStringLengthLowering(): void
    {
        $code = <<<'PHP'
<?php
echo strlen(random_bytes('16'));
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'random_bytes_numeric_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $this->addToAssertionCount(1);
    }

    public function testArrayOperandTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
try {
    random_bytes([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'random_bytes_array_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'random_bytes(): Argument #1 ($length) must be of type int, array given',
            $bc
        );
    }
}
