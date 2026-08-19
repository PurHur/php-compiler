<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only: array vs runtime/boxed null/bool compare must not fail module verify (#32528).
 *
 * php-src: Zend/zend_operators.c compare_function
 *
 * @group llvm
 */
final class ArrayRuntimeNullBoolUnlikeCompare32528JitCompileTest extends TestCase
{
    public function testArrayRuntimeNullBoolUnlikeCompareModuleVerify(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason);
        }
        $code = file_get_contents($root.'/test/repro/issue_array_runtime_null_bool_unlike_compare.php');
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_array_runtime_null_bool_unlike_compare.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
