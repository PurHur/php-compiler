<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapConstants;
use PHPCompiler\ext\ldap\LdapDnJitHelper;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * ldap_connect_wallet() SSOT when Oracle wallet ABI is absent (#31984).
 */
final class LdapConnectWalletJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_connect_wallet.php');
        $this->assertStringContainsString('JitLdapConnectWallet::invoke', $source);
        $this->assertStringNotContainsString('is not implemented for JIT', $source);
        $this->assertStringContainsString('__compiler_ldap_connect_wallet', $source);
        $this->assertStringContainsString('LdapRuntime::ensureLinked', $source);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/ldap/LdapDnJitHelper.php');
        $this->assertStringContainsString('function connectWallet', $helper);
    }

    public function testConnectWithoutWalletAbiReturnsFalseAndWarns(): void
    {
        if (VmLdapNative::walletAvailable()) {
            self::markTestSkipped('Oracle wallet LDAP ABI present — withhold path not exercised');
        }

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];

            return true;
        });
        try {
            $result = LdapDnJitHelper::connect(
                'ldap://127.0.0.1',
                '/tmp/wallet',
                'secret',
                LdapConstants::GSLC_SSL_NO_AUTH,
                null
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame(Variable::TYPE_BOOLEAN, $result->type);
        $this->assertFalse($result->toBool());
        $this->assertNotEmpty($warnings);
        $this->assertSame(\E_USER_WARNING, $warnings[0][0]);
        $this->assertStringContainsString(
            'Oracle wallet LDAP support is not available in this build',
            $warnings[0][1]
        );
    }

    public function testNullUriUsesSameWithholdShape(): void
    {
        if (VmLdapNative::walletAvailable()) {
            self::markTestSkipped('Oracle wallet LDAP ABI present — withhold path not exercised');
        }

        set_error_handler(static fn (): bool => true);
        try {
            $result = LdapDnJitHelper::connect(
                null,
                '/tmp/wallet',
                'secret',
                LdapConstants::GSLC_SSL_NO_AUTH,
                null
            );
        } finally {
            restore_error_handler();
        }

        $this->assertSame(Variable::TYPE_BOOLEAN, $result->type);
        $this->assertFalse($result->toBool());
    }
}
