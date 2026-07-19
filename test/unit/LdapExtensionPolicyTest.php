<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\ldap\LdapExtensionPolicy;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPUnit\Framework\TestCase;

/** LdapExtensionPolicy tracks libldap FFI + Oracle wallet ABI (#18211 / #3369 / #20638). */
final class LdapExtensionPolicyTest extends TestCase
{
    public function testAdvertisesWhenLibldapFfiAvailable(): void
    {
        $available = VmLdapNative::available();
        self::assertSame($available, LdapExtensionPolicy::advertisesExtension());
        self::assertSame($available, LdapExtensionPolicy::advertisesBuiltins());
        self::assertSame($available, LdapExtensionPolicy::advertisesClasses());
    }

    public function testWalletAdvertiseFollowsOracleAbi(): void
    {
        $wallet = VmLdapNative::walletAvailable();
        self::assertSame(
            LdapExtensionPolicy::advertisesExtension() && $wallet,
            LdapExtensionPolicy::advertisesWalletConnect()
        );
    }
}
