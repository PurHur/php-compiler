<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapLinkJitHelper;
use PHPCompiler\ext\ldap\VmLdapConnection;
use PHPCompiler\ext\ldap\VmLdapCore;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** ldap_bind_ext() JIT helper SSOT (#32146). */
final class LdapBindExtJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_link_builtins.php');
        $this->assertStringContainsString('JitLdapLink::invokeBindExt', $source);
        $this->assertStringNotContainsString('ldap_bind_ext() is not implemented for JIT', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapLink.php');
        $this->assertStringContainsString('__compiler_ldap_bind_ext', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapLinkJitHelper::bindExtArgv', $runtime);
        $this->assertStringContainsString('__compiler_ldap_bind_ext', $runtime);
    }

    public function testTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_bind_ext(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::bindExtArgv(999_998, null, null, 0, 0);
    }

    public function testTypeErrorOnNullBytesInDn(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — bind_ext path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_146);

        try {
            $this->expectException(\TypeError::class);
            $this->expectExceptionMessage('ldap_bind_ext(): Argument #2 ($dn) must not contain null bytes');
            LdapLinkJitHelper::bindExtArgv(42_146, "cn=x\0y", null, 1, 0);
        } finally {
            VmLdapConnection::close($object);
        }
    }

    public function testAnonymousBindExtReturnsFalseWithoutLiveDirectory(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — bind_ext path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_147);

        \PHPCompiler\Web\Superglobals::setActiveContext($ctx);
        set_error_handler(static fn (): bool => true);
        try {
            $out = LdapLinkJitHelper::bindExtArgv(42_147, null, null, 0, 0);
            $this->assertSame(Variable::TYPE_BOOLEAN, $out->type);
            $this->assertFalse($out->toBool());
            $this->assertNotSame(0, VmLdapConnection::errno($object));
        } finally {
            restore_error_handler();
            \PHPCompiler\Web\Superglobals::setActiveContext(null);
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
