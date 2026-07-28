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
        $this->assertStringContainsString('NestedClosureInvokeLlvm::ensureLinked', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayReduceJitHelper.php');
        $this->assertStringContainsString('VmClosureInvoke::invokeVariable', $helper);
        $map = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayMapLlvm.php');
        $this->assertStringContainsString('function mapClosure', $map);
        $this->assertStringContainsString('NestedClosureInvoke', $map);
        $arrayMap = (string) file_get_contents(__DIR__.'/../../ext/standard/array_map.php');
        $this->assertStringContainsString('isClosureJitLowerable', $arrayMap);
    }
}
