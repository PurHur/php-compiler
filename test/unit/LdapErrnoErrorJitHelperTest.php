<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapLinkJitHelper;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPUnit\Framework\TestCase;

/** ldap_errno()/ldap_error()/ldap_err2str() JIT helper SSOT (#32106). */
final class LdapErrnoErrorJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_link_builtins.php');
        $this->assertStringContainsString('JitLdapLink::invokeErrno', $source);
        $this->assertStringContainsString('JitLdapLink::invokeError', $source);
        $this->assertStringContainsString('JitLdapLink::invokeErr2str', $source);
        $this->assertStringNotContainsString('ldap_errno() is not implemented for JIT', $source);
        $this->assertStringNotContainsString('ldap_error() is not implemented for JIT', $source);
        $this->assertStringNotContainsString('ldap_err2str() is not implemented for JIT', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapLink.php');
        $this->assertStringContainsString('__compiler_ldap_errno', $jit);
        $this->assertStringContainsString('__compiler_ldap_error', $jit);
        $this->assertStringContainsString('__compiler_ldap_err2str', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapLinkJitHelper::errnoArgv', $runtime);
        $this->assertStringContainsString('LdapLinkJitHelper::errorArgv', $runtime);
        $this->assertStringContainsString('LdapLinkJitHelper::err2strArgv', $runtime);
    }

    public function testErrnoArgvTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_errno(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::errnoArgv(777_777);
    }

    public function testErrorArgvTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_error(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::errorArgv(777_776);
    }

    public function testErr2strArgvReturnsStringWithoutLiveDirectory(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — err2string backend unavailable');
        }
        $message = LdapLinkJitHelper::err2strArgv(0);
        $this->assertIsString($message);
        $this->assertNotSame('', $message);
    }
}

