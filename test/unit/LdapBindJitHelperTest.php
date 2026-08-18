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

/** ldap_bind() JIT helper SSOT (#32001). */
final class LdapBindJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_link_builtins.php');
        $this->assertStringContainsString('JitLdapLink::invokeBind', $source);
        $this->assertStringNotContainsString('ldap_bind() is not implemented for JIT', $source);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapLink.php');
        $this->assertStringContainsString('__compiler_ldap_bind', $jit);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapLinkJitHelper::bindArgv', $runtime);
    }

    public function testTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_bind(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::bindArgv(999_999, null, null, 0, 0);
    }

    public function testTypeErrorOnClosedConnectionHandle(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — live link not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_001);
        VmLdapConnection::close($object);

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('not a valid ldap link resource');
        LdapLinkJitHelper::bindArgv(42_001, null, null, 0, 0);
    }

    public function testAnonymousBindReturnsBoolWithoutLiveDirectory(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — bind path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_002);

        set_error_handler(static fn (): bool => true);
        try {
            $ok = LdapLinkJitHelper::bindArgv(42_002, null, null, 0, 0);
        } finally {
            restore_error_handler();
            VmLdapConnection::close($object);
        }

        $this->assertIsBool($ok);
    }

    private static function ldapContext(): Context
    {
        $runtime = new Runtime();
        $runtime->load(new \PHPCompiler\ext\ldap\Module());

        return $runtime->vmContext;
    }
}
