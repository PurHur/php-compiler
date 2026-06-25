<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Boxed value compare JIT routes through VmValueCompare PHP SSOT (#9972). */
final class JitValueCompareRuntimeShrinkTest extends TestCase
{
    public function testJitValueCompareIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitValueCompare.php');
        $this->assertStringContainsString('VmValueCompare', $source);
        $this->assertStringNotContainsString('strtol', $source);
        $this->assertStringNotContainsString('identicalValueToValueTyped', $source);
        $this->assertLessThanOrEqual(260, substr_count($source, "\n") + 1);
    }

    public function testVmValueCompareOwnsCompareLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmValueCompare.php');
        $this->assertStringContainsString('looseEqualStringToString', $source);
        $this->assertStringContainsString('nativeLongEqualWithResourceIdentity', $source);
        $this->assertGreaterThan(1000, substr_count($source, "\n") + 1);
    }
}
