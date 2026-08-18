<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapLinkJitHelper;
use PHPCompiler\ext\ldap\VmLdapConnection;
use PHPCompiler\ext\ldap\VmLdapCore;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/** ldap_start_tls() JIT helper SSOT (#32109). */
final class LdapStartTlsJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_link_builtins.php');
        $this->assertStringContainsString('JitLdapLink::invokeStartTls', $source);
        $this->assertStringNotContainsString('ldap_start_tls() is not implemented for JIT', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapLink.php');
        $this->assertStringContainsString('__compiler_ldap_start_tls', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapLinkJitHelper::startTlsArgv', $runtime);
    }

    public function testTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_start_tls(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::startTlsArgv(777_773);
    }

    public function testStartTlsWithoutLiveDirectoryReturnsFalseAndSetsErrno(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — start_tls path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_109);

        set_error_handler(static fn (): bool => true);
        try {
            $ok = LdapLinkJitHelper::startTlsArgv(42_109);
            $this->assertFalse($ok);
            $this->assertNotSame(0, VmLdapConnection::errno($object));
        } finally {
            restore_error_handler();
            VmLdapConnection::close($object);
        }
    }

    private static function ldapContext(): Context
    {
        $runtime = new Runtime();
        $runtime->load(new \PHPCompiler\ext\ldap\Module());

        return $runtime->vmContext;
    }
}
