<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\ArrayReduceCallbackPolicy;
use PHPUnit\Framework\TestCase;

/** Thin AOT array_reduce — NestedClosureInvoke scaffolding + honest decline (#24156). */
final class ArrayReduceThinAotClosureDeclineTest extends TestCase
{
    public function testThinAotClosureRejectionMessageIsExplicit(): void
    {
        $msg = ArrayReduceCallbackPolicy::thinAotClosureRejectionMessage();
        $this->assertStringContainsString('array_reduce() with a Closure callback', $msg);
        $this->assertStringContainsString('thin standalone AOT', $msg);
    }

    public function testNestedClosureInvokeScaffoldingPresent(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReduceRuntime.php');
        $this->assertStringContainsString('NestedClosureInvokeLlvm::ensureLinked', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayReduceJitHelper.php');
        $this->assertStringContainsString('VmClosureCall::invokeVariable', $helper);
    }
}
