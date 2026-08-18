<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapResultJitHelper;
use PHPCompiler\ext\ldap\VmLdapCore;
use PHPCompiler\ext\ldap\VmLdapConnection;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** ldap_compare() JIT helper SSOT (#32121). */
final class LdapCompareJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_result_builtins.php');
        $this->assertStringContainsString('JitLdapResult::invokeCompare', $source);
        $this->assertStringNotContainsString('ldap_compare() is not implemented for JIT', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapResult.php');
        $this->assertStringContainsString('__compiler_ldap_compare', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapResultJitHelper::compareArgv', $runtime);
    }

    public function testTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_compare(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapResultJitHelper::compareArgv(888_888, 'cn=x', 'cn', 'x');
    }

    public function testCompareReturnsBoolOrIntWithoutLiveDirectory(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — compare path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_003);

        set_error_handler(static fn (): bool => true);
        try {
            @VmLdapCore::bind($object, null, null);
            $out = LdapResultJitHelper::compareArgv(42_003, 'cn=x', 'cn', 'x');
        } finally {
            restore_error_handler();
            VmLdapConnection::close($object);
        }

        $this->assertContains(
            $out->type,
            [Variable::TYPE_BOOLEAN, Variable::TYPE_INTEGER],
            'compare must return bool or int (-1)'
        );
    }

    private static function ldapContext(): Context
    {
        $runtime = new Runtime();
        $runtime->load(new \PHPCompiler\ext\ldap\Module());

        return $runtime->vmContext;
    }
}
