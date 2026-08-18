<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapDnJitHelper;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * ldap_connect() JIT helper SSOT when libldap FFI is absent (#32000).
 */
final class LdapConnectJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_connect.php');
        $this->assertStringContainsString('JitLdapConnect::invoke', $source);
        $this->assertStringNotContainsString('is not implemented for JIT', $source);
        $this->assertStringContainsString('__compiler_ldap_connect', $source);
        $this->assertStringContainsString('__compiler_ldap_link_register', $source);
        $this->assertStringContainsString('LdapRuntime::ensureLinked', $source);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/ldap/LdapDnJitHelper.php');
        $this->assertStringContainsString('function connectUri', $helper);
    }

    public function testConnectWithoutLibldapReturnsFalseAndWarns(): void
    {
        if (VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI present — withhold path not exercised');
        }

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];

            return true;
        });
        try {
            $result = LdapDnJitHelper::connectUri('ldap://127.0.0.1', 0, 0);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(Variable::TYPE_BOOLEAN, $result->type);
        $this->assertFalse($result->toBool());
        $this->assertNotEmpty($warnings);
        $this->assertSame(\E_USER_WARNING, $warnings[0][0]);
        $this->assertStringContainsString('Could not create session handle', $warnings[0][1]);
    }

    public function testNullUriWithoutLibldapUsesSameWithholdShape(): void
    {
        if (VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI present — withhold path not exercised');
        }

        set_error_handler(static fn (): bool => true);
        try {
            $result = LdapDnJitHelper::connectUri(null, 1, 3389);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(Variable::TYPE_BOOLEAN, $result->type);
        $this->assertFalse($result->toBool());
    }
}
