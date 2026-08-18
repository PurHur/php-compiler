<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for ldap_escape / ldap_dn2ufn / ldap_explode_dn / ldap_connect_wallet
 * (#6352, #18173, #22212, #22276, #31984).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer StringStrcoll #22256).
 * php-src: ext/ldap/ldap.c
 */
final class LdapRuntime
{
    private const ESCAPE_HELPER_PATH = '/ext/ldap/LdapEscapeJitHelper.php';

    private const DN_HELPER_PATH = '/ext/ldap/LdapDnJitHelper.php';

    private const LDAP_ESCAPE_HELPER = 'PHPCompiler\\ext\\ldap\\LdapEscapeJitHelper::ldapEscape';

    private const LDAP_DN2UFN_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::dn2ufn';

    private const LDAP_EXPLODE_DN_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::explodeDn';

    private const LDAP_CONNECT_WALLET_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::connectWallet';

    /** @var list<string> */
    private const ESCAPE_HELPERS = [
        self::LDAP_ESCAPE_HELPER,
    ];

    /** @var list<string> */
    private const DN_HELPERS = [
        self::LDAP_DN2UFN_HELPER,
        self::LDAP_EXPLODE_DN_HELPER,
        self::LDAP_CONNECT_WALLET_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_escape',
            'ldap_escape_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $strPtr,
            self::LDAP_ESCAPE_HELPER,
            self::ESCAPE_HELPER_PATH,
            self::ESCAPE_HELPERS,
            '#22276'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_dn2ufn',
            'ldap_dn2ufn_bridge_entry',
            [$strPtr],
            $valuePtr,
            self::LDAP_DN2UFN_HELPER,
            self::DN_HELPER_PATH,
            self::DN_HELPERS,
            '#22212'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_explode_dn',
            'ldap_explode_dn_bridge_entry',
            [$strPtr, $i64],
            $valuePtr,
            self::LDAP_EXPLODE_DN_HELPER,
            self::DN_HELPER_PATH,
            self::DN_HELPERS,
            '#22212'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_connect_wallet',
            'ldap_connect_wallet_bridge_entry',
            [$strPtr, $strPtr, $strPtr, $i64],
            $valuePtr,
            self::LDAP_CONNECT_WALLET_HELPER,
            self::DN_HELPER_PATH,
            self::DN_HELPERS,
            '#31984'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
