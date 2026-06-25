<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ArrayAccess JIT routes through VmArrayAccess PHP SSOT (#10246). */
final class ArrayAccessHelperRuntimeShrinkTest extends TestCase
{
    public function testArrayAccessHelperIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayAccessHelper.php');
        $this->assertStringContainsString('VmArrayAccess', $source);
        $this->assertStringNotContainsString('invokeOffsetMethod', $source);
        $this->assertStringNotContainsString('arrayAccessMethodCandidates', $source);
        $this->assertLessThanOrEqual(85, substr_count($source, "\n") + 1);
    }

    public function testVmArrayAccessOwnsOffsetLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmArrayAccess.php');
        $this->assertStringContainsString('invokeOffsetMethod', $source);
        $this->assertStringContainsString('arrayAccessMethodCandidates', $source);
        $this->assertStringContainsString('writableArrayAccessReceiver', $source);
        $this->assertGreaterThan(200, substr_count($source, "\n") + 1);
    }
}
