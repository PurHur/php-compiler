<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for echo $undef ?? default (#32445).
 *
 * php-src: Zend/zend_vm_def.h ZEND_COALESCE
 *
 * @group llvm
 */
final class CoalesceUndefEcho32445JitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — ?? JIT compile test needs LLVM (#32445)');
        }
    }

    public function testUndefCoalesceEchoModuleVerify(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents($this->repoRoot.'/test/repro/issue_32445_coalesce_undef_echo.php');
        $this->assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, 'issue_32445_coalesce_undef_echo.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
