<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_find family JIT routes through ArrayFindJitHelper PHP (#17547, #17674). */
final class ArrayFindRuntimeShrinkTest extends TestCase
{
    public function testArrayFindClosureUsesNestedPhpWalk(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFindHelper.php');
        $this->assertStringContainsString('ArrayFindRuntime::walkClosure', $helper);
        $this->assertStringContainsString('ArrayFindRuntime::walk', $helper);
        $this->assertStringNotContainsString('buildFromNativeArray', $helper);
        $this->assertStringNotContainsString('resolvePredicateHandler', $helper);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFindRuntime.php');
        $this->assertStringContainsString('walkWithClosure', $runtime);
        $this->assertStringContainsString('walkWithNamedCallback', $runtime);
        $this->assertStringContainsString('ArrayFindLlvm::walkWithClosure', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFindLlvm.php');
        $this->assertStringContainsString('NestedClosureInvoke', $llvm);
        $this->assertStringContainsString('MODE_FIND_KEY', $llvm);

        $jitHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayFindJitHelper.php');
        $this->assertStringContainsString('walkWithClosure', $jitHelper);
        $this->assertStringContainsString('walkWithNamedCallback', $jitHelper);
        $this->assertStringContainsString('VmClosureCall::invoke', $jitHelper);
        $this->assertStringContainsString('VmUserCall::invokeTwo', $jitHelper);
        // Ternaries must not be inline call args (NestedJIT ARG_SEND null slots, #26824).
        $this->assertStringNotContainsString('invoke($ctx, $closureState, $keyFirst ?', $jitHelper);
        $this->assertStringNotContainsString('invokeTwo($ctx, $userFn, $keyFirst ?', $jitHelper);
    }

    public function testArrayFindHelperLineCountShrink(): void
    {
        $lines = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFindHelper.php'), "\n") + 1;
        $this->assertLessThan(250, $lines, 'ArrayFindHelper should be glue-only after #17674');
    }
}
