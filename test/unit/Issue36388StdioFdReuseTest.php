<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStdStreamConstants;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Process-lifetime STDIN/STDOUT/STDERR — no FD leak across Runtime instances (#36388).
 */
final class Issue36388StdioFdReuseTest extends TestCase
{
    protected function tearDown(): void
    {
        VmStdStreamConstants::resetProcessHandlesForTesting();
        parent::tearDown();
    }

    public function testVmStdStreamConstantsCachesProcessHandles(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStdStreamConstants.php');
        $this->assertStringContainsString('processHandles', $source);
        $this->assertStringContainsString('ensureProcessHandles', $source);
        $this->assertStringContainsString('#36388', $source);
    }

    public function testRepeatedRuntimeDoesNotGrowFdCount(): void
    {
        if ('Linux' !== PHP_OS_FAMILY || !is_dir('/proc/'.getmypid().'/fd')) {
            $this->markTestSkipped('Linux /proc fd listing required');
        }

        VmStdStreamConstants::resetProcessHandlesForTesting();
        $baseline = self::countFds();
        $r0 = new Runtime();
        unset($r0);
        $afterFirst = self::countFds();
        $this->assertGreaterThanOrEqual($baseline, $afterFirst);

        for ($i = 0; $i < 8; $i++) {
            $r = new Runtime();
            unset($r);
        }
        $afterMany = self::countFds();
        // Allow ±2 for incidental allocator noise; must not grow ~3 FDs per Runtime.
        $this->assertLessThanOrEqual($afterFirst + 2, $afterMany, "fds after first={$afterFirst} after many={$afterMany}");

        $handles = VmStdStreamConstants::processHandles();
        $this->assertArrayHasKey('STDIN', $handles);
        $this->assertArrayHasKey('STDOUT', $handles);
        $this->assertArrayHasKey('STDERR', $handles);
    }

    private static function countFds(): int
    {
        return count(glob('/proc/'.getmypid().'/fd/*') ?: []);
    }
}
