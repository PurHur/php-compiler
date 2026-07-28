<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * str_starts_with()/str_ends_with() JIT must use binary-safe findOffset (#4390, #24161).
 *
 * @group llvm
 */
final class StrStartsEndsWithBinaryJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — str_starts_with/str_ends_with binary JIT compile test needs LLVM');
        }
    }

    public function testStrStartsWithLowersToBinarySafeHelper(): void
    {
        $code = <<<'PHP'
<?php
$hay = 'a'.chr(0).'b';
echo str_starts_with($hay, 'a'.chr(0)) ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'str_starts_with_binary_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $bc = $runtime->loadJitContext()->module->printToString();
        $this->assertStringContainsString('memcmp', $bc);
        $this->assertStringContainsString('call i32 @memcmp', $bc);
    }

    public function testStrEndsWithLowersToBinarySafeHelper(): void
    {
        $code = <<<'PHP'
<?php
$hay = 'a'.chr(0).'b';
echo str_ends_with($hay, chr(0).'b') ? '1' : '0';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'str_ends_with_binary_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $bc = $runtime->loadJitContext()->module->printToString();
        $this->assertStringContainsString('memcmp', $bc);
        $this->assertStringContainsString('call i32 @memcmp', $bc);
    }
}
