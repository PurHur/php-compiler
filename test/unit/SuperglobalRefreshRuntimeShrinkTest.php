<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Superglobal refresh JIT routes through SuperglobalRefreshJitHelper PHP not ~1.9k-line LLVM (#9907). */
final class SuperglobalRefreshRuntimeShrinkTest extends TestCase
{
    private const BASELINE_LOC = 1901;

    public function testSuperglobalRefreshRuntimeUsesJitHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('SuperglobalRefreshJitHelper::buildGetTable', $source);
        $this->assertStringContainsString('SuperglobalRefreshStandaloneLlvm::implement', $source);
        $this->assertStringNotContainsString('emitRefreshMain', $source);
        $this->assertStringNotContainsString('__phpc_sg_parse_form_encoded', $source);
    }

    public function testSuperglobalRefreshRuntimeShrunkAtLeastEightyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshRuntime.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(self::BASELINE_LOC * 0.2), $loc, 'SuperglobalRefreshRuntime.php LOC');
    }

    public function testStandaloneLlvmQuarantined(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshStandaloneLlvm.php'), "\n") + 1;
        $this->assertGreaterThan(1500, $loc, 'SuperglobalRefreshStandaloneLlvm.php retains LLVM quarantine');
    }
}
