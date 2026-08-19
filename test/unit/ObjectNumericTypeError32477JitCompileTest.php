<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only: object unary +/- / ⊙ int must not abort lowering (#32477).
 *
 * php-src: Zend/zend_operators.c IS_OBJECT arithmetic TypeError
 *
 * @group llvm
 */
final class ObjectNumericTypeError32477JitCompileTest extends TestCase
{
    public function testObjectNumericTypeErrorModuleVerify(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason);
        }
        $code = file_get_contents($root.'/test/repro/issue_object_numeric_typeerror.php');
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_object_numeric_typeerror.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
