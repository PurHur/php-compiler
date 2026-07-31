<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\ldap\LdapExtensionPolicy;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPUnit\Framework\TestCase;

/** LdapExtensionPolicy host / ENABLE gate (#18211 / #23857 / #24536). */
final class LdapExtensionPolicyTest extends TestCase
{
    private ?string $savedProfile = null;
    private ?string $savedEnable = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->savedProfile = \is_string($raw) ? $raw : null;
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);

        $en = getenv('PHP_COMPILER_ENABLE_LDAP');
        $this->savedEnable = \is_string($en) ? $en : null;
        putenv('PHP_COMPILER_ENABLE_LDAP');
        unset($_ENV['PHP_COMPILER_ENABLE_LDAP'], $_SERVER['PHP_COMPILER_ENABLE_LDAP']);
    }

    protected function tearDown(): void
    {
        if (null === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->savedProfile;
            $_SERVER['PHP_COMPILER_PROFILE'] = $this->savedProfile;
        }
        if (null === $this->savedEnable) {
            putenv('PHP_COMPILER_ENABLE_LDAP');
            unset($_ENV['PHP_COMPILER_ENABLE_LDAP'], $_SERVER['PHP_COMPILER_ENABLE_LDAP']);
        } else {
            putenv('PHP_COMPILER_ENABLE_LDAP='.$this->savedEnable);
            $_ENV['PHP_COMPILER_ENABLE_LDAP'] = $this->savedEnable;
            $_SERVER['PHP_COMPILER_ENABLE_LDAP'] = $this->savedEnable;
        }
    }

    public function testReferenceProfileWithholdsWithoutHostLdap(): void
    {
        if (\extension_loaded('ldap')) {
            self::markTestSkipped('host php-ldap loaded — cannot assert reference withhold');
        }

        self::assertFalse(LdapExtensionPolicy::advertisesExtension());
        self::assertFalse(LdapExtensionPolicy::advertisesBuiltins());
        self::assertFalse(LdapExtensionPolicy::advertisesClasses());
    }

    public function testForwardProfileAloneDoesNotAdvertise(): void
    {
        if (\extension_loaded('ldap')) {
            self::markTestSkipped('host php-ldap loaded');
        }
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI unavailable');
        }

        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $_SERVER['PHP_COMPILER_PROFILE'] = '8.4';

        self::assertFalse(LdapExtensionPolicy::advertisesExtension());
    }

    public function testExplicitEnableAdvertisesWhenLibldapFfiAvailable(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI unavailable');
        }
        if (\extension_loaded('ldap')) {
            self::markTestSkipped('host php-ldap already advertises');
        }

        putenv('PHP_COMPILER_ENABLE_LDAP=1');
        $_ENV['PHP_COMPILER_ENABLE_LDAP'] = '1';
        $_SERVER['PHP_COMPILER_ENABLE_LDAP'] = '1';

        self::assertTrue(LdapExtensionPolicy::advertisesExtension());
        self::assertTrue(LdapExtensionPolicy::advertisesBuiltins());
        self::assertTrue(LdapExtensionPolicy::advertisesClasses());
    }

    public function testWalletAdvertiseFollowsOracleAbi(): void
    {
        putenv('PHP_COMPILER_ENABLE_LDAP=1');
        $_ENV['PHP_COMPILER_ENABLE_LDAP'] = '1';
        $_SERVER['PHP_COMPILER_ENABLE_LDAP'] = '1';

        $wallet = VmLdapNative::walletAvailable();
        self::assertSame(
            LdapExtensionPolicy::advertisesExtension() && $wallet,
            LdapExtensionPolicy::advertisesWalletConnect()
        );
    }

    public function testPhantomComplianceRunsOnlyWhenWithheld(): void
    {
        self::assertTrue(LdapExtensionPolicy::isLdapPhantomComplianceCase(
            'stdlib/ldap_extension_loaded_phantom_reference'
        ));
        self::assertSame(
            !LdapExtensionPolicy::advertisesExtension(),
            LdapExtensionPolicy::runsLdapCompliance('stdlib/ldap_extension_loaded_phantom_reference')
        );
        self::assertTrue(LdapExtensionPolicy::runsLdapCompliance('ext/ldap_connect'));
    }
}
