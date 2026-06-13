<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for str_contains() strict Z_PARAM_STR lowering (#5018).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_contains), str_starts_with, str_ends_with
 *
 * @group llvm
 */
final class StrContainsTypeJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — str_contains type JIT compile test needs LLVM');
        }
    }

    public function testIntOperandTypeErrorLowering(): void
    {
        $target = $this->repoRoot.'/test/fixtures/aot/compile-only/str_contains_type_error.php';
        $this->assertFileExists($target);
        $code = (string) file_get_contents($target);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'str_contains_type_error_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $this->addToAssertionCount(1);
    }
}
