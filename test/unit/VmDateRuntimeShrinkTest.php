<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDatePure;
use PHPUnit\Framework\TestCase;

/** VmDate time/mktime/gettimeofday without libc FFI (#13765, php-in-php). */
final class VmDateRuntimeShrinkTest extends TestCase
{
    public function testVmDateHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDate.php');
        $this->assertStringContainsString('VmDatePure::', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('libc.so', $source);
        $this->assertStringNotContainsString('private static function ffi(', $source);
        $this->assertStringNotContainsString("extension_loaded('ffi')", $source);
    }

    public function testVmDatePureHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDatePure.php');
        $this->assertStringContainsString('function_exists', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testTimeMktimeGettimeofdayWorkWithFfiDisabled(): void
    {
        if (!VmDatePure::available()) {
            $this->markTestSkipped('host time/getdate unavailable');
        }

        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertGreaterThan(1_000_000_000, VmDate::time());
            $this->assertSame(946684800, VmDate::mktime(0, 0, 0, 1, 1, 2000));
            $ht = VmDate::gettimeofdayArray();
            $this->assertNotNull($ht->find('sec'));
            $this->assertNotNull($ht->find('usec'));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testGetdateBreakdownMatchesEpoch(): void
    {
        $ht = VmDate::getdate(0);
        $this->assertSame(1970, $ht->find('year')->resolveIndirect()->toInt());
        $this->assertSame(0, $ht->find('seconds')->resolveIndirect()->toInt());
    }
}
