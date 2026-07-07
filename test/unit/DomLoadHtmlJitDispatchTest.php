<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** DOM JIT dispatch wiring for loadHTML/getElementById (#17130). */
final class DomLoadHtmlJitDispatchTest extends TestCase
{
    public function testVmDomInstanceInvokeRoutesLoadHtmlAndGetElementById(): void
    {
        $invoke = (string) file_get_contents(__DIR__.'/../../ext/dom/VmDomInstanceInvoke.php');
        $dispatch = (string) file_get_contents(__DIR__.'/../../ext/dom/VmDomJitDispatch.php');
        $this->assertStringContainsString("'loadhtml'", $invoke);
        $this->assertStringContainsString("'getelementbyid'", $invoke);
        $this->assertStringContainsString('function loadHTML', $dispatch);
        $this->assertStringContainsString('function getElementById', $dispatch);
    }

    public function testDomInstanceMethodRuntimeDeclaresCompiledHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodRuntime.php');
        $this->assertStringContainsString('COMPILED_HELPERS', $source);
        $this->assertStringContainsString('VmDomInstanceInvoke::invoke1Object', $source);
    }

    public function testNestedVariableToObjectHandlerRegistered(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/NestedVmVariableMethodLlvm.php');
        $this->assertStringContainsString('VariableToObject::class', $source);
    }
}
