<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for getrusage() Z_PARAM_LONG lowering (#4600).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getrusage)
 *
 * @group llvm
 */
final class GetrusageTypeJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — getrusage type JIT compile test needs LLVM');
        }
    }

    public function testNumericStringModeLowering(): void
    {
        $code = <<<'PHP'
<?php
var_dump(getrusage("0") !== false);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'getrusage_numeric_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $this->addToAssertionCount(1);
    }

    public function testArrayOperandTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
try {
    getrusage([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'getrusage_array_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'getrusage(): Argument #1 ($mode) must be of type int, array given',
            $bc
        );
    }
}
