<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for enum case string interpolation (#4819).
 *
 * php-src: Zend/zend_operators.c — encapsed string cast on enum objects
 *
 * @group llvm
 */
final class EnumStringInterpolationJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — enum string interpolation JIT compile test needs LLVM (#4819)');
        }
    }

    public function testEnumInterpolationModuleVerify(): void
    {
        $path = $this->repoRoot.'/test/fixtures/aot/compile-only/enum_string_interpolation.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_string_interpolation_jit.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
