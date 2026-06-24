<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringTriggerErrorJit must route through TriggerErrorJitHelper PHP, not LLVM fprintf tables (#9293). */
final class TriggerErrorRuntimeShrinkTest extends TestCase
{
    public function testStringTriggerErrorJitUsesTriggerErrorJitHelperNotLlvmBodies(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTriggerErrorJit.php');
        $this->assertStringContainsString('TriggerErrorJitHelper', $source);
        $this->assertStringNotContainsString('emitStderrPrintCliError', $source);
        $this->assertStringNotContainsString('selectErrorPrefix', $source);
        $this->assertStringNotContainsString('recordAndMaybePrint', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/TriggerErrorJitHelper.php');
    }
}
