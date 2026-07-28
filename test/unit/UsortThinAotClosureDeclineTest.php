<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPUnit\Framework\TestCase;

/** Thin AOT usort — NestedClosureInvoke scaffolding + honest decline (#24156). */
final class UsortThinAotClosureDeclineTest extends TestCase
{
    public function testThinAotClosureRejectionMessageIsExplicit(): void
    {
        $msg = UsortCallbackPolicy::thinAotClosureRejectionMessage('usort');
        $this->assertStringContainsString('usort() with a Closure comparator', $msg);
        $this->assertStringContainsString('thin standalone AOT', $msg);
    }

    public function testNestedClosureInvokeScaffoldingPresent(): void
    {
        $this->assertSame(
            'phpcompiler\\ext\\standard\\vmclosureinvoke::invokevariable',
            NestedClosureInvokeLlvm::PROXY
        );
        $usort = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UsortRuntime.php');
        $this->assertStringContainsString('NestedClosureInvokeLlvm::ensureLinked', $usort);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/UsortJitHelper.php');
        $this->assertStringContainsString('sortVariableValuesViaTarget', $helper);
    }

    public function testDuplicateFromNestedHandlerRegistered(): void
    {
        $this->assertTrue(
            \PHPCompiler\JIT\NestedVmVariableMethodLlvm::isNestedVariableMethod('duplicatefrom')
        );
    }
}
