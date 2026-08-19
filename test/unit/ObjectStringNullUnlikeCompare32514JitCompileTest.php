<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only: object vs string/null compare must not fail module verify (#32514).
 *
 * php-src: Zend/zend_operators.c compare_function
 *
 * @group llvm
 */
final class ObjectStringNullUnlikeCompare32514JitCompileTest extends TestCase
{
    public function testObjectStringNullUnlikeCompareModuleVerify(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason);
        }
        $code = file_get_contents($root.'/test/repro/issue_object_string_null_unlike_compare.php');
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_object_string_null_unlike_compare.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
