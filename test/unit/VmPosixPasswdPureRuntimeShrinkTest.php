<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmProcessIdentityPure;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** VmPosix name lookup via /etc/passwd+group without getpwnam/getgrnam FFI (#12454). */
final class VmPosixPasswdPureRuntimeShrinkTest extends TestCase
{
    public function testVmPosixDelegatesNameLookupToPurePasswdGroup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('VmProcessIdentityPure::uidForName', $source);
        $this->assertStringContainsString('VmProcessIdentityPure::gidForName', $source);
        $this->assertStringNotContainsString('getpwnam', $source);
        $this->assertStringNotContainsString('getgrnam', $source);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcessIdentityPure.php');
        $this->assertStringContainsString('/etc/passwd', $pure);
        $this->assertStringContainsString('/etc/group', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
    }

    public function testResolveRootIdentityViaPurePasswdOnLinux(): void
    {
        if (!VmProcessIdentityPure::available() || !\is_readable('/etc/passwd')) {
            $this->markTestSkipped('Linux /etc/passwd only');
        }

        $rootUid = VmPosix::uidForName('root');
        $rootGid = VmPosix::gidForName('root');
        $this->assertNotNull($rootUid);
        $this->assertNotNull($rootGid);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertSame($rootUid, VmPosix::uidForName('root'));
            $this->assertSame($rootGid, VmPosix::gidForName('root'));

            $userVar = new Variable();
            $userVar->string('root');
            $this->assertSame($rootUid, VmFs::resolveUserUid($userVar));

            $groupVar = new Variable();
            $groupVar->string('root');
            $this->assertSame($rootGid, VmFs::resolveGroupGid($groupVar));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
