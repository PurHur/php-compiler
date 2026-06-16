<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM module verify for enum case instance method dispatch (#9002, Zend/zend_enum.c).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class EnumInstanceMethodJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — enum instance method JIT compile test needs LLVM (#9002)');
        }
    }

    public function testEnumInstanceMethodDispatchModuleVerify(): void
    {
        $code = file_get_contents(
            $this->repoRoot.'/test/repro/maintainer_gap_enum_instance_method.php'
        );
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_enum_instance_method.php');
        $runtime->jitCompileBlock($block);

        $verify = new \ReflectionMethod($runtime->loadJitContext(), 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($runtime->loadJitContext());
        $this->addToAssertionCount(1);
    }
}
