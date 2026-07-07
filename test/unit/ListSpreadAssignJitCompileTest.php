<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT compile verify for list spread destructuring (#4885, Zend/zend_execute.c).
 *
 * @group llvm
 */
final class ListSpreadAssignJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — list spread JIT compile test needs LLVM (#4885)');
        }
    }

    public function testListSpreadAssignModuleVerify(): void
    {
        if (!CompilerVersion::supportsListDestructuringSpreadAssign()) {
            $this->markTestSkipped('list spread assign disabled on reference profile');
        }
        $path = $this->repoRoot.'/test/compliance/cases/language/list_destructuring_spread.phpt';
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
        $this->assertStringContainsString('list_unpack', $bc);

        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }

    public function testKeyedListSpreadAssignModuleVerify(): void
    {
        if (!CompilerVersion::supportsListDestructuringSpreadAssign()) {
            $this->markTestSkipped('list spread assign disabled on reference profile');
        }
        $path = $this->repoRoot.'/test/compliance/cases/language/list_destructuring_keyed_spread_jit.phpt';
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
        $this->assertStringContainsString('list_spread_tail', $bc);

        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
