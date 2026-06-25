<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Value box bool writer routes through VmValueBoxWriteBool PHP SSOT (#9570). */
final class ValueBoxWriteBoolRuntimeShrinkTest extends TestCase
{
    public function testValueBoxWriteBoolJitIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueBoxWriteBoolJit.php');
        $this->assertStringContainsString('VmValueBoxWriteBool', $source);
        $this->assertStringNotContainsString('emitWriteBool', $source);
        $this->assertLessThanOrEqual(25, substr_count($source, "\n") + 1);
    }

    public function testVmValueBoxWriteBoolOwnsLlvmLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueBoxWriteBool.php');
        $this->assertStringContainsString('emitWriteBool', $source);
        $this->assertStringContainsString('TYPE_NATIVE_BOOL', $source);
        $this->assertGreaterThan(60, substr_count($source, "\n") + 1);
    }
}
