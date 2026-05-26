<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * LLVM verify for array_change_key_case() (#78 Phase 2 stdlib).
 *
 * @group llvm
 */
final class ArrayChangeKeyCaseJitCompileTest extends TestCase
{
    public function testChangeKeyCaseModuleVerifies(): void
    {
        $code = file_get_contents(__DIR__.'/../compliance/cases/stdlib/array_change_key_case_jit.phpt');
        $this->assertNotFalse($code);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT/s', $code, $matches)) {
            $this->fail('array_change_key_case_jit.phpt FILE section missing');
        }

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($matches[1], 'array_change_key_case_jit.phpt');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
    }
}
