<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * str_contains()/strpos() JIT must not lower to NUL-unsafe libc strstr (#4146).
 *
 * @group llvm
 */
final class StrContainsBinaryJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — str_contains binary JIT compile test needs LLVM');
        }
    }

    public function testStrContainsLowersToBinarySafeHelper(): void
    {
        $code = <<<'PHP'
<?php
echo str_contains('hello', 'ell') ? 'y' : 'n';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'str_contains_binary_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $bc = $runtime->loadJitContext()->module->printToString();
        $this->assertStringContainsString('__phpc_string_find_substr', $bc);
        $this->assertStringContainsString('call i32 @__phpc_string_find_substr', $bc);
    }

    public function testStrposLowersToBinarySafeHelper(): void
    {
        $code = <<<'PHP'
<?php
echo strpos('hello', 'ell') == 1 ? 'y' : 'n';
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'strpos_binary_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $bc = $runtime->loadJitContext()->module->printToString();
        $this->assertStringContainsString('__phpc_string_find_substr', $bc);
        $this->assertStringContainsString('call i32 @__phpc_string_find_substr', $bc);
    }
}
