<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only: assigned object vs string/int compare (#32540).
 *
 * @group llvm
 */
final class RuntimeObjectScalarUnlikeCompare32540JitCompileTest extends TestCase
{
    public function testRuntimeObjectScalarUnlikeCompareModuleVerify(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason);
        }
        $code = file_get_contents($root.'/test/repro/issue_runtime_object_scalar_unlike_compare.php');
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_runtime_object_scalar_unlike_compare.php');
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
