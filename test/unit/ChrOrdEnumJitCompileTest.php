<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for chr()/ord() enum-case TypeError guards (#5673, #5836).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(chr), PHP_FUNCTION(ord)
 *
 * @group llvm
 */
final class ChrOrdEnumJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — chr/ord enum JIT compile test needs LLVM');
        }
    }

    public function testChrEnumCaseTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 65; }
chr(E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'chr_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'chr(): Argument #1 ($codepoint) must be of type int',
            $bc
        );
    }

    public function testOrdEnumCaseTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 65; }
ord(E::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'ord_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'ord(): Argument #1 ($character) must be of type string',
            $bc
        );
    }
}
