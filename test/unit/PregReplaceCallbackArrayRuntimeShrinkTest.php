<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** preg_replace_callback_array() JIT/AOT routes through PregReplaceCallbackArrayRuntime + PregJitHelper (#3568). */
final class PregReplaceCallbackArrayRuntimeShrinkTest extends TestCase
{
    public function testJitLoweringUsesRuntimeBridgeNotLogicExceptionStub(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPregReplaceCallbackArray.php');
        $this->assertStringContainsString('PregReplaceCallbackArrayRuntime::ensureLinked', $jit);
        $this->assertStringNotContainsString('not implemented for JIT/AOT', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregReplaceCallbackArrayRuntime.php');
        $this->assertStringContainsString('PregJitHelper::replaceCallbackArrayArgv', $runtime);
        $this->assertStringContainsString('PregMatchRuntime::ensureLinked', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/PregJitHelper.php');
        $this->assertStringContainsString('replaceCallbackArrayArgv', $helper);
        $this->assertStringContainsString('VmPregReplaceCallbackArray::invoke', $helper);
    }
}
