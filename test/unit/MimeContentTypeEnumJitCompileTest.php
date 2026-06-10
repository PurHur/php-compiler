<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for mime_content_type() enum-case TypeError guards (#6196).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(mime_content_type)
 *
 * @group llvm
 */
final class MimeContentTypeEnumJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — mime_content_type enum JIT compile test needs LLVM');
        }
    }

    public function testCompileOnlyFixture(): void
    {
        $path = $this->repoRoot.'/test/fixtures/aot/compile-only/mime_content_type_enum.php';
        $this->assertFileExists($path);
        $code = (string) file_get_contents($path);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, basename($path));
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    public function testMimeContentTypeEnumCaseTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum Ep: string { case P = '/tmp/foo.php'; }
mime_content_type(Ep::P);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'mime_content_type_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'mime_content_type(): Argument #1 ($filename_or_stream) must be of type string',
            $bc
        );
        $this->assertStringContainsString('__compiler_jit_raise_type_error', $bc);
    }
}
