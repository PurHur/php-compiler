<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Issue #7917: VmFs chown/chgrp must not delegate to host posix_getpwnam/getgrnam. */
final class VmFsPosixIdentityRuntimeShrinkTest extends TestCase
{
    public function testVmFsDoesNotReferenceHostPosixNameLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmPosix::uidForName', $source);
        $this->assertStringContainsString('VmPosix::gidForName', $source);
        $this->assertStringNotContainsString("function_exists('posix_getpwnam')", $source);
        $this->assertStringNotContainsString("function_exists('posix_getgrnam')", $source);
        $this->assertStringNotContainsString('\\posix_getpwnam(', $source);
        $this->assertStringNotContainsString('\\posix_getgrnam(', $source);
    }

    public function testVmPosixExposesLibcNameLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('getpwnam', $source);
        $this->assertStringContainsString('getgrnam', $source);
        $this->assertStringContainsString('uidForName', $source);
        $this->assertStringContainsString('gidForName', $source);
    }

    public function testResolveRootIdentityWhenFfiAvailable(): void
    {
        if (!VmPosix::ffiAvailable()) {
            $this->markTestSkipped('libc FFI unavailable on this host');
        }
        $rootUid = VmPosix::uidForName('root');
        $rootGid = VmPosix::gidForName('root');
        $this->assertNotNull($rootUid);
        $this->assertNotNull($rootGid);
        $uidVar = new Variable();
        $uidVar->int($rootUid);
        $this->assertSame($rootUid, VmFs::resolveUserUid($uidVar));
        $userVar = new Variable();
        $userVar->string('root');
        $this->assertSame($rootUid, VmFs::resolveUserUid($userVar));
        $groupVar = new Variable();
        $groupVar->string('root');
        $this->assertSame($rootGid, VmFs::resolveGroupGid($groupVar));
    }
}
