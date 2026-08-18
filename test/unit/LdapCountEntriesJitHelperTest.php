<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapResultJitHelper;
use PHPCompiler\ext\ldap\VmLdapConnection;
use PHPCompiler\ext\ldap\VmLdapCore;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPCompiler\ext\ldap\VmLdapResult;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/** ldap_count_entries() JIT helper SSOT (#32172). */
final class LdapCountEntriesJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_search_builtins.php');
        $this->assertStringContainsString('JitLdapResult::invokeCountEntries', $source);
        $this->assertStringNotContainsString('ldap_count_entries() is not implemented for JIT', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapResult.php');
        $this->assertStringContainsString('__compiler_ldap_count_entries', $jit);
        $this->assertStringContainsString('__compiler_ldap_result_register', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapResultJitHelper::countEntriesArgv', $runtime);
        $this->assertStringContainsString('LdapResultJitHelper::registerHandleArgv', $runtime);
        $this->assertStringContainsString('__compiler_ldap_count_entries', $runtime);
    }

    public function testTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_count_entries(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapResultJitHelper::countEntriesArgv(888_889, 888_890);
    }

    public function testTypeErrorOnNonResultHandle(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — count_entries path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_172);

        try {
            $this->expectException(\TypeError::class);
            $this->expectExceptionMessage('ldap_count_entries(): Argument #2 ($result) must be of type LDAP\\Result, mixed given');
            LdapResultJitHelper::countEntriesArgv(42_172, 999_991);
        } finally {
            VmLdapConnection::close($object);
        }
    }

    public function testCountEntriesOnBindExtResultWithoutLiveDirectory(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — count_entries path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_173);

        \PHPCompiler\Web\Superglobals::setActiveContext($ctx);
        set_error_handler(static fn (): bool => true);
        try {
            $out = \PHPCompiler\ext\ldap\LdapLinkJitHelper::bindExtArgv(42_173, null, null, 0, 0);
            if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $out->type) {
                self::markTestSkipped('anonymous bind_ext did not return LDAP\\Result');
            }
            VmLdapResult::claimPendingJitHandle(42_174);
            $count = LdapResultJitHelper::countEntriesArgv(42_173, 42_174);
            $this->assertIsInt($count);
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
