<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for object == JIT lowering (#4766).
 *
 * php-src: Zend/zend_operators.c — compare_objects / ZEND_IS_EQUAL
 *
 * MCJIT execute remains gated until operand lowering matches object <=> (#4661).
 *
 * @group llvm
 */
final class ObjectLooseEqualsJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — object == JIT compile test needs LLVM (#4766)');
        }
    }

    public function testObjectLooseEqualsModuleVerify(): void
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/object_loose_equals.phpt';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail('object_loose_equals.phpt FILE section missing');
        }

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($matches[1], 'object_loose_equals.phpt');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
