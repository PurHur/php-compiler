<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for array literal duplicate keys (#4703).
 *
 * php-src: Zend/zend_compile.c, zend_hash.c — last duplicate key wins
 *
 * MCJIT execute remains unstable in harness (jit-runtime-probe #98); guards IR lowering.
 *
 * @group llvm
 */
final class ArrayLiteralDuplicateKeyJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — array literal duplicate key JIT compile test needs LLVM (#4703)');
        }
    }

    public function testArrayLiteralDuplicateKeyModuleVerify(): void
    {
        $runtime = new Runtime();
        $code = $this->phptFixtureCode('array_literal_duplicate_key.phpt');
        $block = $runtime->parseAndCompile($code, 'array_literal_duplicate_key.phpt');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    private function phptFixtureCode(string $file): string
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail($file.' FILE section missing');
        }

        return $matches[1];
    }
}
