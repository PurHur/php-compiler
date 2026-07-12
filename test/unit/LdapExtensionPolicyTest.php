<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\ldap\LdapExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** LdapExtensionPolicy phantom withhold without ext/ldap (#18211). */
final class LdapExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseWithoutExtLdap(): void
    {
        self::assertFalse(LdapExtensionPolicy::advertisesExtension());
        self::assertFalse(LdapExtensionPolicy::advertisesBuiltins());
    }
}
