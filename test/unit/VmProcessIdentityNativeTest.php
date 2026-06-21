<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmProcessIdentity;
use PHPCompiler\ext\standard\VmProcessIdentityNative;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** VmProcessIdentityNative libc path without host POSIX delegation (#7891). */
final class VmProcessIdentityNativeTest extends TestCase
{
    public function testVmProcessIdentityPrefersNativeOverHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcessIdentity.php');
        $this->assertStringContainsString('VmProcessIdentityNative::available()', $source);
        $this->assertStringContainsString('VmProcessIdentityNative::getuid()', $source);
        $this->assertStringContainsString('VmProcessIdentityNative::getgid()', $source);
        $this->assertStringContainsString('VmProcessIdentityNative::geteuid()', $source);
        $this->assertStringContainsString('VmProcessIdentityNative::getpwuidName', $source);
        $this->assertStringNotContainsString("function_exists('posix_", $source);
        $this->assertStringNotContainsString("function_exists('getuid')", $source);
        $this->assertStringNotContainsString("function_exists('getgid')", $source);
        $this->assertStringNotContainsString("function_exists('geteuid')", $source);
        $this->assertStringNotContainsString("function_exists('getpwuid')", $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\posix_[a-z_]+\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\getpwuid\\s*\\(/', $source);
    }

    public function testVmDateGetmygrgidUsesProcessIdentity(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDate.php');
        $this->assertStringContainsString('VmProcessIdentity::getmygid()', $source);
        $this->assertStringNotContainsString('posix_getgid', $source);
    }

    public function testVmDateGetmypidUsesProcessIdentityNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDate.php');
        $this->assertStringContainsString('VmProcessIdentityNative::getpid()', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\getmypid\\s*\\(/', $source);
    }

    public function testNativeDefinesLibcIdentityFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcessIdentityNative.php');
        $this->assertStringContainsString('uid_t getuid(void)', $source);
        $this->assertStringContainsString('gid_t getgid(void)', $source);
        $this->assertStringContainsString('uid_t geteuid(void)', $source);
        $this->assertStringContainsString('pid_t getpid(void)', $source);
        $this->assertStringContainsString('struct passwd *getpwuid', $source);
        $this->assertStringContainsString('$ffi->getuid()', $source);
        $this->assertStringContainsString('$ffi->getpid()', $source);
    }

    public function testNativeIdentityMatchesHostOnLinux(): void
    {
        if (!VmProcessIdentityNative::available()) {
            $this->markTestSkipped('FFI process identity unavailable');
        }

        $uid = VmProcessIdentityNative::getuid();
        $gid = VmProcessIdentityNative::getgid();
        $euid = VmProcessIdentityNative::geteuid();
        $this->assertNotNull($uid);
        $this->assertNotNull($gid);
        $this->assertNotNull($euid);
        $this->assertGreaterThanOrEqual(0, $uid);
        $this->assertGreaterThanOrEqual(0, $gid);

        $name = VmProcessIdentityNative::getpwuidName($euid);
        $this->assertNotNull($name, 'getpwuidName must resolve passwd entry when FFI is available');
        $this->assertNotSame('', $name);

        $this->assertSame($uid, VmProcessIdentity::getmyuid());
        $this->assertSame($gid, VmProcessIdentity::getmygid());
        $pid = VmProcessIdentityNative::getpid();
        if (null !== $pid) {
            $this->assertGreaterThan(0, $pid);
            $this->assertSame($pid, VmDate::getmypid());
        }
        $user = VmProcessIdentity::getCurrentUser();
        $this->assertSame($name, $user);
        $this->assertNotSame('Unknown', $user);
    }
}
