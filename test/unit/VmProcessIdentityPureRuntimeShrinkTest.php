<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmProcessIdentity;
use PHPCompiler\ext\standard\VmProcessIdentityNative;
use PHPCompiler\ext\standard\VmProcessIdentityPure;
use PHPUnit\Framework\TestCase;

/** VmProcessIdentityPure /proc path without libc FFI (#9017, #12182). */
final class VmProcessIdentityPureRuntimeShrinkTest extends TestCase
{
    public function testVmProcessIdentityNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcessIdentityNative.php');
        $this->assertStringContainsString('VmProcessIdentityPure::getpid()', $source);
        $this->assertStringContainsString('VmProcessIdentityPure::getuid()', $source);
        $this->assertStringContainsString('VmProcessIdentityPure::getpwuidName', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->getpid/', $source);
    }

    public function testJitDateRoutesGetmypidThroughProcessIdentityJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('ProcessIdentityJit::getmypid', $source);
        $this->assertStringNotContainsString("lookupFunction('getpid')", $source);
    }

    public function testJitGetCurrentUserRoutesThroughProcessIdentityJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetCurrentUser.php');
        $this->assertStringContainsString('ProcessIdentityJit::getCurrentUser', $source);
        $this->assertStringNotContainsString("lookupFunction('getpwuid')", $source);
        $this->assertStringNotContainsString("lookupFunction('geteuid')", $source);
    }

    public function testGetCurrentUserEmptyForVirtualScriptPaths(): void
    {
        $this->assertSame('', VmProcessIdentity::getCurrentUserForScript(''));
        $this->assertSame('', VmProcessIdentity::getCurrentUserForScript('-'));
        $this->assertSame('', VmProcessIdentity::getCurrentUserForScript('Standard input code'));
        $this->assertSame('', VmProcessIdentity::getCurrentUserForScript('Command line code'));
    }

    public function testGetmypidWorksWithFfiDisabledOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/self/status')) {
            $this->markTestSkipped('/proc/self/status unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmProcessIdentityPure::available());
            $pid = VmProcessIdentityPure::getpid();
            $this->assertNotNull($pid);
            $this->assertGreaterThan(0, $pid);
            $this->assertSame($pid, VmProcessIdentityNative::getpid());
            $this->assertSame($pid, VmDate::getmypid());
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testIdentityFieldsMatchProcStatusOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !VmProcessIdentityPure::available()) {
            $this->markTestSkipped('/proc identity unavailable');
        }

        $uid = VmProcessIdentityPure::getuid();
        $gid = VmProcessIdentityPure::getgid();
        $euid = VmProcessIdentityPure::geteuid();
        $this->assertNotNull($uid);
        $this->assertNotNull($gid);
        $this->assertNotNull($euid);

        $this->assertSame($uid, VmProcessIdentity::getmyuid());
        $this->assertSame($gid, VmProcessIdentity::getmygid());

        $ownerUid = VmFs::fileOwner(__FILE__);
        if (false !== $ownerUid) {
            $ownerName = VmProcessIdentityPure::getpwuidName($ownerUid);
            if (null !== $ownerName) {
                $this->assertSame(
                    $ownerName,
                    VmProcessIdentity::getCurrentUserForScript(__FILE__)
                );
            }
        }
    }
}
