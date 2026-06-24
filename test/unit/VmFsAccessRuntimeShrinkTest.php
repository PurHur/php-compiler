<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsAccessNative;
use PHPCompiler\ext\standard\VmFsAccessPure;
use PHPCompiler\ext\standard\VmStatPath;
use PHPUnit\Framework\TestCase;

/** is_* access checks via stat mode bits without libc access(2) FFI (#8990). */
final class VmFsAccessRuntimeShrinkTest extends TestCase
{
    public function testVmFsAccessNativeDelegatesToPureWithoutAccessFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsAccessNative.php');
        $this->assertStringContainsString('VmFsAccessPure::access', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->access/', $source);
        $this->assertDoesNotMatchRegularExpression('/int access\\(/', $source);
    }

    public function testVmFsAccessPureUsesStatCacheAndProcessIdentity(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsAccessPure.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertStringContainsString('VmProcessIdentityNative::getuid', $source);
        $this->assertStringContainsString('VmProcessIdentityNative::getgid', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->access/', $source);
    }

    public function testVmStatPathRoutesThroughPureAccess(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStatPath.php');
        $this->assertStringContainsString('VmFsAccessPure::', $source);
        $this->assertStringContainsString('VmStatCache::stat', $source);
    }

    public function testJitStatDoesNotCallLibcAccess(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStat.php');
        $this->assertStringContainsString('StatPathRuntime::', $source);
        $this->assertDoesNotMatchRegularExpression("/lookupFunction\\('access'\\)/", $source);
        $this->assertDoesNotMatchRegularExpression("/lookupFunction\\('stat'\\)/", $source);
    }

    public function testAccessChecksMatchZendOnLinuxWhenFfiAvailable(): void
    {
        if (!VmFsAccessNative::available()) {
            $this->markTestSkipped('stat/process identity FFI unavailable');
        }

        $path = __FILE__;
        $this->assertSame(\is_readable($path), VmStatPath::isReadable($path));
        $this->assertSame(\is_writable($path), VmStatPath::isWritable($path));
        $this->assertSame(\is_executable($path), VmStatPath::isExecutable($path));
        $this->assertSame(\is_readable('.'), VmStatPath::isReadable('.'));
        $this->assertSame(\is_writable('.'), VmStatPath::isWritable('.'));
        if (\is_executable('/bin/sh')) {
            $this->assertTrue(VmStatPath::isExecutable('/bin/sh'));
        }
    }

    public function testPureAccessReturnsFalseForMissingPath(): void
    {
        if (!VmFsAccessNative::available()) {
            $this->markTestSkipped('stat/process identity FFI unavailable');
        }

        $this->assertFalse(VmFsAccessPure::isReadable('/no/such/phpc-access-path-'.bin2hex(random_bytes(4))));
    }
}
