<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for count_chars()/chunk_split() enum-case TypeError guards (#6032).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(count_chars), PHP_FUNCTION(chunk_split)
 *
 * @group llvm
 */
final class CountCharsChunkSplitEnumJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — count_chars/chunk_split enum JIT compile test needs LLVM');
        }
    }

    public function testCountCharsEnumCaseTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum ES: string { case X = 'x'; }
count_chars(ES::X);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'count_chars_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'count_chars(): Argument #1 ($string) must be of type string',
            $bc
        );
    }

    public function testChunkSplitEnumLengthTypeErrorLowering(): void
    {
        $code = <<<'PHP'
<?php
enum EI: int { case A = 1; }
chunk_split('abc', EI::A);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'chunk_split_enum_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'chunk_split(): Argument #2 ($length) must be of type int',
            $bc
        );
    }
}
