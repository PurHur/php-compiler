<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only: object/array bitwise &|^~ / <<>> must not abort lowering (#32486).
 *
 * php-src: Zend/zend_operators.c IS_OBJECT / IS_ARRAY bitwise TypeError
 *
 * @group llvm
 */
final class ObjectArrayBitwiseTypeError32486JitCompileTest extends TestCase
{
    public function testObjectArrayBitwiseTypeErrorModuleVerify(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason);
        }
        $code = file_get_contents($root.'/test/repro/issue_object_array_bitwise_typeerror.php');
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_object_array_bitwise_typeerror.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
