<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmString;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * str_padded() VM + AOT compile-only gates (#7044).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_pad)
 *
 * @group llvm
 */
final class StrPaddedJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — str_padded AOT compile test needs LLVM');
        }
    }

    public function testUtf8PaddingVmSemantics(): void
    {
        $this->assertSame('hi   ', VmString::strPadded('hi', 5));
        $this->assertSame('000hi', VmString::strPadded('hi', 5, '0', 0));
        $this->assertSame('--hi--', VmString::strPadded('hi', 6, '-', 2));
        $this->assertSame('日  ', VmString::strPadded('日', 3));
        $this->assertSame('日本本本', VmString::strPadded('日', 4, '本'));
    }

    public function testCompileOnlyFixtureLint(): void
    {
        $path = $this->repoRoot.'/test/fixtures/aot/compile-only/str_padded_basic.php';
        $this->assertFileExists($path);
        $code = (string) file_get_contents($path);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, basename($path));
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    public function testJitLowersStrPaddedCalls(): void
    {
        $code = <<<'PHP'
<?php
echo str_padded('hi', 5), "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'str_padded_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString('strpad', strtolower($bc));
    }
}
