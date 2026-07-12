<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapExtensionPolicy;
use PHPCompiler\ext\standard\BuiltinIntrospectionPolicy;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** ldap_escape() introspection — builtin advertised without full ext/ldap (#18173). */
final class LdapEscapeIntrospectionTest extends TestCase
{
    public function testLdapEscapeAdvertisedWithoutExtensionModule(): void
    {
        $this->assertTrue(LdapExtensionPolicy::advertisesBuiltins());
        $this->assertFalse(LdapExtensionPolicy::advertisesExtension());
        $this->assertTrue(BuiltinIntrospectionPolicy::functionIsAdvertised('ldap_escape'));
        $this->assertFalse(BuiltinIntrospectionPolicy::extensionIsAdvertised('ldap'));

        $runtime = new Runtime();
        $this->assertArrayHasKey('ldap_escape', $runtime->vmContext->functions);
    }
}
