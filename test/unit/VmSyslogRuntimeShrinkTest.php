<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmSyslog;
use PHPCompiler\ext\standard\VmSyslogPure;
use PHPUnit\Framework\TestCase;

/** VmSyslog — syslog without libc FFI (#12211). */
final class VmSyslogRuntimeShrinkTest extends TestCase
{
    public function testVmSyslogDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSyslog.php');
        $this->assertStringContainsString('VmSyslogPure::syslog', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->syslog', $source);
    }

    public function testVmSyslogPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSyslogPure.php');
        $this->assertStringContainsString('/dev/log', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\syslog\\s*\\(/', $source);
    }

    public function testSyslogRoundTripWithFfiDisabled(): void
    {
        if (!VmSyslogPure::available()) {
            $this->markTestSkipped('/dev/log unavailable on this host');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmSyslog::available());
            $this->assertTrue(VmSyslog::openlog('phpc-test', StdlibConstants::LOG_PID, StdlibConstants::LOG_USER));
            $this->assertTrue(VmSyslog::syslog(StdlibConstants::LOG_INFO, 'runtime-shrink probe'));
            $this->assertTrue(VmSyslog::closelog());
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
