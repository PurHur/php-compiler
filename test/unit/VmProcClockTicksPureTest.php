<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmGetrusageNative;
use PHPCompiler\ext\standard\VmProcClockTicksPure;
use PHPUnit\Framework\TestCase;

/** CLK_TCK SSOT for getrusage timeval conversion (#13522). */
final class VmProcClockTicksPureTest extends TestCase
{
    protected function tearDown(): void
    {
        VmProcClockTicksPure::resetCacheForTests();
        $prev = \getenv('PHP_COMPILER_PROC_CLK_TCK');
        if (false === $prev) {
            \putenv('PHP_COMPILER_PROC_CLK_TCK');
        } else {
            \putenv('PHP_COMPILER_PROC_CLK_TCK='.$prev);
        }
        parent::tearDown();
    }

    public function testVmGetrusagePureUsesProcClockTicksSsot(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmGetrusagePure.php');
        $this->assertStringContainsString('VmProcClockTicksPure::clockTicksPerSecond', $source);
        $this->assertStringNotContainsString('private static function clockTicksPerSecond', $source);
    }

    public function testProcClockTicksHonorsEnvOverride(): void
    {
        VmProcClockTicksPure::resetCacheForTests();
        \putenv('PHP_COMPILER_PROC_CLK_TCK=37');
        $this->assertSame(37, VmProcClockTicksPure::clockTicksPerSecond());
    }

    public function testProcClockTicksDiscoversHostHzOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux only');
        }

        VmProcClockTicksPure::resetCacheForTests();
        \putenv('PHP_COMPILER_PROC_CLK_TCK');
        $hz = VmProcClockTicksPure::clockTicksPerSecond();
        $this->assertGreaterThan(0, $hz);

        $ref = (int) \trim((string) @\shell_exec('getconf CLK_TCK 2>/dev/null'));
        if ($ref > 0) {
            $this->assertSame($ref, $hz);
        }
    }

    public function testGetrusageUsecWithinThreeTimesOfZendOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\function_exists('getrusage') || !VmGetrusageNative::available()) {
            $this->markTestSkipped('Linux libc + /proc getrusage only');
        }

        VmProcClockTicksPure::resetCacheForTests();
        \putenv('PHP_COMPILER_PROC_CLK_TCK');

        $zend = \getrusage();
        $vm = VmGetrusageNative::getrusage(0);
        $this->assertIsArray($zend);
        $this->assertIsArray($vm);

        $zendUsec = (int) ($zend['ru_utime.tv_usec'] ?? 0);
        $vmUsec = (int) ($vm['ru_utime.tv_usec'] ?? 0);
        if ($zendUsec <= 0 && $vmUsec <= 0) {
            $this->markTestSkipped('idle usec zero on host');
        }

        $ratio = \max($zendUsec, $vmUsec) / \max(1, \min($zendUsec, $vmUsec));
        $this->assertLessThanOrEqual(3.0, $ratio, "zend={$zendUsec} vm={$vmUsec}");
    }
}
