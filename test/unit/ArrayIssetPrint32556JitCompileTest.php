<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only: isset()/print on packed array must not abort Analyzer (#32556).
 *
 * php-src: Zend/zend_execute.c ZEND_ISSET_ISEMPTY_CV; Zend/zend_vm_def.h ZEND_PRINT
 *
 * @group llvm
 */
final class ArrayIssetPrint32556JitCompileTest extends TestCase
{
    public function testArrayIssetPrintModuleVerify(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason);
        }
        $code = file_get_contents($root.'/test/repro/issue_32556_isset_print_packed_array.php');
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_32556_isset_print_packed_array.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
