<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for stream_context_get_default / set_default (#6367).
 *
 * @group llvm
 */
final class StreamContextGetDefaultAotCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — stream_context_get_default JIT compile test needs LLVM');
        }
    }

    public function testGetDefaultLoweringCompiles(): void
    {
        $target = $this->repoRoot.'/test/fixtures/aot/compile-only/stream_context_get_default.php';
        $this->assertFileExists($target);
        $code = (string) file_get_contents($target);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'stream_context_get_default_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $this->addToAssertionCount(1);
    }
}
