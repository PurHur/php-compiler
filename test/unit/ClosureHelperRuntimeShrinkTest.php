<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Closure JIT routes create/call LLVM through VmClosure PHP (#10344). */
final class ClosureHelperRuntimeShrinkTest extends TestCase
{
    public function testClosureHelperRoutesThroughVmClosure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ClosureHelper.php');
        $this->assertStringContainsString('VmClosure', $source);
        $this->assertStringNotContainsString('JitValueBox::', $source);
        $this->assertStringNotContainsString('storeTargetName', $source);
        $this->assertStringNotContainsString('resolveIndirectCall', $source);
        $this->assertLessThanOrEqual(115, substr_count($source, "\n") + 1);
        $this->assertFileExists(__DIR__.'/../../lib/VM/VmClosure.php');
    }

    public function testVmClosureOwnsCaptureLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmClosure.php');
        $this->assertStringContainsString('snapshotCapturesForClosure', $source);
        $this->assertStringContainsString('snapshotCapture', $source);
        $this->assertStringContainsString('referenceCapture', $source);
        $this->assertStringContainsString('ClosureSupport', $source);
        $this->assertGreaterThan(150, substr_count($source, "\n") + 1);
    }
}
