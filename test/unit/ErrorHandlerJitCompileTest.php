<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * LLVM verify for set_error_handler() / restore_error_handler() (#1379, #2456).
 *
 * Calls {@see JIT\Context::compileCommon()} after IR lowering (no MCJIT link) so
 * harness hosts with broken MCJIT still catch handler-shim signature bugs.
 *
 * @group llvm
 */
final class ErrorHandlerJitCompileTest extends TestCase
{
    public function testErrorHandlerShimModuleVerifies(): void
    {
        $code = file_get_contents(__DIR__.'/../compliance/cases/stdlib/restore_error_handler_jit.phpt');
        $this->assertNotFalse($code);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT/s', $code, $matches)) {
            $this->fail('restore_error_handler_jit.phpt FILE section missing');
        }

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($matches[1], 'restore_error_handler_jit.phpt');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
    }
}
