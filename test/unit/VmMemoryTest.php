<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmMemory;
use PHPCompiler\VM\MemoryAccounting;
use PHPUnit\Framework\TestCase;

/** VmMemory RSS path without host getrusage() delegation (issue #7287, #4862). */
final class VmMemoryTest extends TestCase
{
    public function testSourceDoesNotDelegateToHostGetrusage(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmMemory.php');
        $this->assertDoesNotMatchRegularExpression('/function_exists\\s*\\(\\s*[\'"]getrusage/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\getrusage\\s*\\(/', $source);
        $this->assertStringContainsString('/proc/self/statm', $source);
    }

    public function testVmMemorySourceDoesNotDelegateToHostFileGetContents(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmMemory.php');
        $this->assertStringContainsString('VmFsReadNative::read', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\file_get_contents\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)file_get_contents\\s*\\(/', $source);
    }

    public function testRealUsageReadsProcStatmOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !is_readable('/proc/self/statm')) {
            $this->markTestSkipped('Linux /proc/self/statm required for RSS probe');
        }

        $this->assertGreaterThan(0, VmMemory::getUsage(true));
    }

    public function testUsageAfterPeakQueryToleratesInterpreterSlop(): void
    {
        MemoryAccounting::resetPeakToCurrent();
        MemoryAccounting::markPeakQuery(MemoryAccounting::peakBytes());
        MemoryAccounting::noteBytes(6);
        $this->assertSame(0, MemoryAccounting::usageAfterPeakQuery());
    }

    /** Real peak must drop after free + reset — Zend AG(real_peak), not sticky RSS (#26769). */
    public function testRealPeakLowersAfterFreeAndReset(): void
    {
        VmMemory::beginRequest();
        MemoryAccounting::beginRequest();

        MemoryAccounting::noteBytes(5 * 1024 * 1024);
        $peak1 = VmMemory::getPeakUsage(true);
        MemoryAccounting::noteBytes(-(5 * 1024 * 1024));
        VmMemory::resetPeakUsage();
        $peak2 = VmMemory::getPeakUsage(true);

        $this->assertGreaterThan(0, $peak1);
        $this->assertLessThan($peak1, $peak2);
        $this->assertGreaterThan(0, VmMemory::getUsage(true));
    }
}
