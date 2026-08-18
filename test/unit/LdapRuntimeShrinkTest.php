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
        $this->assertStringContainsString('LdapLinkJitHelper::bindArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::saslBindArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::unbindArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::registerHandleArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::errnoArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::errorArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::err2strArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::setOptionIntArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::getOptionIntOkArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::getOptionValueArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::startTlsArgv', $source);
        $this->assertStringContainsString('LdapLinkJitHelper::setRebindProcClearArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('__compiler_ldap_escape', $source);
        $this->assertStringContainsString('__compiler_ldap_connect_wallet', $source);
        $this->assertStringContainsString('__compiler_ldap_bind', $source);
        $this->assertStringContainsString('__compiler_ldap_sasl_bind', $source);
        $this->assertStringContainsString('__compiler_ldap_unbind', $source);
        $this->assertStringContainsString('__compiler_ldap_errno', $source);
        $this->assertStringContainsString('__compiler_ldap_error', $source);
        $this->assertStringContainsString('__compiler_ldap_err2str', $source);
        $this->assertStringContainsString('__compiler_ldap_set_option', $source);
        $this->assertStringContainsString('__compiler_ldap_get_option', $source);
        $this->assertStringContainsString('__compiler_ldap_get_option_value', $source);
        $this->assertStringContainsString('__compiler_ldap_start_tls', $source);
        $this->assertStringContainsString('__compiler_ldap_set_rebind_proc', $source);
        $this->assertStringContainsString('__compiler_ldap_compare', $source);
        $this->assertStringContainsString('ldap_connect_bridge_entry', $source);
        $this->assertSame(18, \preg_match_all('/JitVmHelperLink::ensureBridge\(/', $source));
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('ensureEscapeHelperCompiled', $source);
        $this->assertStringNotContainsString('implementEscapeBridge', $source);
        $this->assertLessThan(360, \substr_count($source, "\n") + 1);
    }
}
