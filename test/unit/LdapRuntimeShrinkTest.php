<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ldap_* JIT routes through Ldap*JitHelper PHP (#18173 / #22212).
 * NestedJIT via JitVmHelperLink::ensureBridge (#22276 / peer #22256).
 */
final class LdapRuntimeShrinkTest extends TestCase
{
    public function testLdapRuntimeUsesJitVmHelperLinkForAllBridges(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapEscapeJitHelper::ldapEscape', $source);
        $this->assertStringContainsString('LdapDnJitHelper::dn2ufn', $source);
        $this->assertStringContainsString('LdapDnJitHelper::explodeDn', $source);
        $this->assertStringContainsString('LdapDnJitHelper::connectWallet', $source);
        $this->assertStringContainsString('LdapDnJitHelper::connectUri', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('__compiler_ldap_escape', $source);
        $this->assertStringContainsString('__compiler_ldap_connect_wallet', $source);
        $this->assertStringContainsString('ldap_connect_bridge_entry', $source);
        $this->assertSame(5, \preg_match_all('/JitVmHelperLink::ensureBridge\(/', $source));
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('ensureEscapeHelperCompiled', $source);
        $this->assertStringNotContainsString('implementEscapeBridge', $source);
        $this->assertLessThan(150, \substr_count($source, "\n") + 1);
    }
}
