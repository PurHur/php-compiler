<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for implicit nullable typed parameters (#4767, #4449).
 *
 * php-src: Zend/zend_compile.c (nullable default on non-?T types), zend_execute.c
 *
 * MCJIT execute: ImplicitNullableParamJitExecuteTest (#4767); compliance JITTest when jit-runtime-probe green.
 *
 * @group llvm
 */
final class ImplicitNullableParamJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — implicit nullable JIT compile test needs LLVM (#4767)');
        }
    }

    public function testImplicitNullableParamModuleVerify(): void
    {
        $runtime = new Runtime();
        $code = $this->phptFixtureCode('implicit_nullable_param.phpt');
        $block = $runtime->parseAndCompile($code, 'implicit_nullable_param.phpt');
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
