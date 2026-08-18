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

/** ldap_set_rebind_proc() JIT helper SSOT (#32148). */
final class LdapSetRebindProcJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_link_builtins.php');
        $this->assertStringContainsString('JitLdapLink::invokeSetRebindProc', $source);
        $this->assertStringNotContainsString('ldap_set_rebind_proc() is not implemented for JIT', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapLink.php');
        $this->assertStringContainsString('__compiler_ldap_set_rebind_proc', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapLinkJitHelper::setRebindProcClearArgv', $runtime);
    }

    public function testTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_set_rebind_proc(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::setRebindProcClearArgv(777_748);
    }

    public function testClearWithoutLiveDirectoryReturnsTrue(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — set_rebind_proc path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_148);

        try {
            $ok = LdapLinkJitHelper::setRebindProcClearArgv(42_148);
            $this->assertTrue($ok);
        } finally {
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
