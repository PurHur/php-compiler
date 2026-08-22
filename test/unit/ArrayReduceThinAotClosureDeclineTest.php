<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Thin AOT array_reduce — NestedClosureInvoke scaffolding (#24156). */
final class ArrayReduceThinAotClosureDeclineTest extends TestCase
{
    public function testNestedClosureInvokeScaffoldingPresent(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReduceRuntime.php');
        // Closures only — string-callback NestedJIT must not register NestedClosureInvoke (#33721).
        $this->assertStringContainsString('NestedClosureInvokeLlvm::ensureLinked', $runtime);
        $this->assertStringContainsString('ArrayReduceLlvm::reduceWithClosure', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayReduceJitHelper.php');
        $this->assertStringNotContainsString('VmClosureInvoke', $helper);
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayReduceLlvm.php');
        $this->assertStringContainsString('function reduceWithClosure', $llvm);
        $this->assertStringContainsString('function reduceWithUserFunction', $llvm);
        $map = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayMapLlvm.php');
        $this->assertStringContainsString('function mapClosure', $map);
        $this->assertStringContainsString('NestedClosureInvoke', $map);
        $arrayMap = (string) file_get_contents(__DIR__.'/../../ext/standard/array_map.php');
        $this->assertStringContainsString('isClosureJitLowerable', $arrayMap);
    }
}
