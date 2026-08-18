<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\ldap\LdapConstants;
use PHPCompiler\ext\ldap\LdapLinkJitHelper;
use PHPCompiler\ext\ldap\VmLdapConnection;
use PHPCompiler\ext\ldap\VmLdapCore;
use PHPCompiler\ext\ldap\VmLdapNative;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/** ldap_set_option()/ldap_get_option() JIT helper SSOT (#32107). */
final class LdapSetGetOptionJitHelperTest extends TestCase
{
    public function testCallDelegatesToJitLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ldap/ldap_link_builtins.php');
        $this->assertStringContainsString('JitLdapLink::invokeSetOption', $source);
        $this->assertStringContainsString('JitLdapLink::invokeGetOption', $source);
        $this->assertStringNotContainsString('ldap_set_option() is not implemented for JIT', $source);
        $this->assertStringNotContainsString('ldap_get_option() is not implemented for JIT', $source);

        $jit = (string) file_get_contents(__DIR__.'/../../ext/ldap/JitLdapLink.php');
        $this->assertStringContainsString('__compiler_ldap_set_option', $jit);
        $this->assertStringContainsString('__compiler_ldap_get_option', $jit);
        $this->assertStringContainsString('__compiler_ldap_get_option_value', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LdapRuntime.php');
        $this->assertStringContainsString('LdapLinkJitHelper::setOptionIntArgv', $runtime);
        $this->assertStringContainsString('LdapLinkJitHelper::getOptionIntOkArgv', $runtime);
        $this->assertStringContainsString('LdapLinkJitHelper::getOptionValueArgv', $runtime);
    }

    public function testSetOptionTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_set_option(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::setOptionIntArgv(777_771, 1, LdapConstants::LDAP_OPT_PROTOCOL_VERSION, 3, 1);
    }

    public function testGetOptionTypeErrorOnNonConnectionHandle(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('ldap_get_option(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given');
        LdapLinkJitHelper::getOptionIntOkArgv(777_772, 1, LdapConstants::LDAP_OPT_PROTOCOL_VERSION);
    }

    public function testUnsupportedValueKindWarnsAndReturnsFalse(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = [$errno, $errstr];

            return true;
        });
        try {
            $ok = LdapLinkJitHelper::setOptionIntArgv(0, 0, LdapConstants::LDAP_OPT_PROTOCOL_VERSION, 0, 0);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($ok);
        $this->assertNotEmpty($warnings);
        $this->assertSame(\E_USER_WARNING, $warnings[0][0]);
        $this->assertStringContainsString('Type not supported for this option', $warnings[0][1]);
    }

    public function testSetGetProtocolVersionRoundTripWithoutLiveDirectory(): void
    {
        if (!VmLdapNative::available()) {
            self::markTestSkipped('libldap FFI absent — option path not exercised');
        }
        $ctx = self::ldapContext();
        $linkVar = VmLdapCore::connect('ldap://127.0.0.1', null, $ctx);
        if (false === $linkVar) {
            self::markTestSkipped('ldap_connect failed in container');
        }
        $object = $linkVar->toObject();
        VmLdapConnection::enqueuePendingJitHandle($object->id);
        VmLdapConnection::claimPendingJitHandle(42_107);

        try {
            $setOk = LdapLinkJitHelper::setOptionIntArgv(
                42_107,
                1,
                LdapConstants::LDAP_OPT_PROTOCOL_VERSION,
                3,
                1
            );
            $getOk = LdapLinkJitHelper::getOptionIntOkArgv(
                42_107,
                1,
                LdapConstants::LDAP_OPT_PROTOCOL_VERSION
            );
            $this->assertTrue($setOk);
            $this->assertSame(1, $getOk);
            $this->assertSame(3, LdapLinkJitHelper::getOptionValueArgv());
        } finally {
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
