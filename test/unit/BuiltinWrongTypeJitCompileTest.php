<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for wrong-type builtin TypeError guards (#4178).
 *
 * php-src: Zend/zend_API.c, ext/standard/streams.c (fread), ext/standard/string.c (substr/chr)
 *
 * @group llvm
 */
final class BuiltinWrongTypeJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — builtin wrong-type JIT compile test needs LLVM');
        }
    }

    public function testFreadLengthArrayTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
$h = fopen('/etc/hostname', 'r');
try {
    fread($h, []);
} catch (TypeError $e) {
    echo $e->getMessage();
}
fclose($h);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'fread_wrong_type_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'fread(): Argument #2 ($length) must be of type int',
            $bc
        );
    }

    public function testCompileOnlyFixture(): void
    {
        $target = $this->repoRoot.'/test/fixtures/aot/compile-only/builtin_wrong_type_error.php';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompileFile($target);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'substr(): Argument #2 ($offset) must be of type int',
            $bc
        );
        $this->assertStringContainsString(
            'chr(): Argument #1 ($codepoint) must be of type int',
            $bc
        );
    }
}
