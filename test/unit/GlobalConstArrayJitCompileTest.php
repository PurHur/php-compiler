<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT compile verify for global const array literals (#4904, Zend/zend_constants.c).
 *
 * @group llvm
 */
final class GlobalConstArrayJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — global const array JIT compile test needs LLVM (#4904)');
        }
    }

    public function testGlobalConstArrayModuleVerify(): void
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/global_const_array.phpt';
        $sections = [];
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        if (preg_match('/--FILE--\s*\n(.*?)\n--EXPECT--/s', $code, $m)) {
            $code = $m[1];
        }

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, $path);
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString('array_const_', $bc);

        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
