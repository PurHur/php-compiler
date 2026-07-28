<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPUnit\Framework\TestCase;

/** Thin AOT usort — NestedClosureInvoke scaffolding (#24156). */
final class UsortThinAotClosureDeclineTest extends TestCase
{
    public function testNestedClosureInvokeScaffoldingPresent(): void
    {
        $this->assertSame(
            'phpcompiler\\ext\\standard\\vmclosureinvoke::invokevariable',
            NestedClosureInvokeLlvm::PROXY
        );
        $usort = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UsortRuntime.php');
        $this->assertStringContainsString('NestedClosureInvokeLlvm::ensureLinked', $usort);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/UsortJitHelper.php');
        $this->assertStringContainsString('VmClosureInvoke::invokeVariable', $helper);
        $this->assertStringContainsString('sortKeyedPairsByKeyViaTarget', (string) file_get_contents(
            __DIR__.'/../../ext/standard/VmClosureCall.php'
        ));
    }

    public function testDuplicateFromNestedHandlerRegistered(): void
    {
        $this->assertTrue(
            \PHPCompiler\JIT\NestedVmVariableMethodLlvm::isNestedVariableMethod('duplicatefrom')
        );
    }
}
