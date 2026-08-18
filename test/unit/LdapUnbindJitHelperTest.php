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

/** ldap_unbind()/ldap_close() JIT helper SSOT (#32002). */
final class LdapUnbindJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_link_builtins.php');
        $this->assertStringContainsString('JitLdapLink::invokeUnbind', $source);
        $this->assertStringNotContainsString('ldap_unbind() is not implemented for JIT', $source);
        $this->assertStringNotContainsString('ldap_close() is not implemented for JIT', $source);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapLink.php');
        $this->assertStringContainsString('__compiler_ldap_unbind', $jit);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapLinkJitHelper::unbindArgv', $runtime);
    }

    public function testTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_unbind(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::unbindArgv(888_888);
    }

    public function testUnbindThenBindTypeErrorWithoutLiveDirectory(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — unbind path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(43_001);

        $this->assertTrue(LdapLinkJitHelper::unbindArgv(43_001));

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('not a valid ldap link resource');
        LdapLinkJitHelper::bindArgv(43_001, null, null, 0, 0);
    }

    private static function ldapContext(): Context
    {
        $runtime = new Runtime();
        $runtime->load(new \PHPCompiler\ext\ldap\Module());

        return $runtime->vmContext;
    }
}
