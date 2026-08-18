<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for ldap_escape / ldap_dn2ufn / ldap_explode_dn / ldap_connect /
 * ldap_connect_wallet / ldap_bind / ldap_bind_ext / ldap_unbind / ldap_errno / ldap_error /
 * ldap_err2str / ldap_set_option / ldap_get_option / ldap_start_tls / ldap_sasl_bind /
 * ldap_compare / ldap_set_rebind_proc
 * (#6352, #18173, #22212, #22276, #31984, #32000, #32001, #32002, #32106, #32107, #32109, #32121, #32146, #32147, #32148).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (peer StringStrcoll #22256).
 * php-src: ext/ldap/ldap.c
 */
final class LdapRuntime
{
    private const ESCAPE_HELPER_PATH = '/ext/ldap/LdapEscapeJitHelper.php';

    private const DN_HELPER_PATH = '/ext/ldap/LdapDnJitHelper.php';

    private const LINK_HELPER_PATH = '/ext/ldap/LdapLinkJitHelper.php';

    private const RESULT_HELPER_PATH = '/ext/ldap/LdapResultJitHelper.php';

    private const LDAP_ESCAPE_HELPER = 'PHPCompiler\\ext\\ldap\\LdapEscapeJitHelper::ldapEscape';

    private const LDAP_DN2UFN_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::dn2ufn';

    private const LDAP_EXPLODE_DN_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::explodeDn';

    private const LDAP_CONNECT_WALLET_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::connectWallet';

    private const LDAP_CONNECT_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::connectUri';

    private const LDAP_LINK_REGISTER_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::registerHandleArgv';

    private const LDAP_BIND_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::bindArgv';

    private const LDAP_BIND_EXT_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::bindExtArgv';

    private const LDAP_SASL_BIND_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::saslBindArgv';

    private const LDAP_UNBIND_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::unbindArgv';

    private const LDAP_ERRNO_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::errnoArgv';

    private const LDAP_ERROR_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::errorArgv';

    private const LDAP_ERR2STR_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::err2strArgv';

    private const LDAP_SET_OPTION_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::setOptionIntArgv';

    private const LDAP_GET_OPTION_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::getOptionIntOkArgv';

    private const LDAP_GET_OPTION_VALUE_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::getOptionValueArgv';

    private const LDAP_START_TLS_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::startTlsArgv';

    private const LDAP_SET_REBIND_PROC_HELPER = 'PHPCompiler\\ext\\ldap\\LdapLinkJitHelper::setRebindProcClearArgv';

    private const LDAP_COMPARE_HELPER = 'PHPCompiler\\ext\\ldap\\LdapResultJitHelper::compareArgv';

    /** @var list<string> */
    private const ESCAPE_HELPERS = [
        self::LDAP_ESCAPE_HELPER,
    ];

    /** @var list<string> */
    private const DN_HELPERS = [
        self::LDAP_DN2UFN_HELPER,
        self::LDAP_EXPLODE_DN_HELPER,
        self::LDAP_CONNECT_WALLET_HELPER,
        self::LDAP_CONNECT_HELPER,
    ];

    /** @var list<string> */
    private const LINK_HELPERS = [
        self::LDAP_LINK_REGISTER_HELPER,
        self::LDAP_BIND_HELPER,
        self::LDAP_BIND_EXT_HELPER,
        self::LDAP_SASL_BIND_HELPER,
        self::LDAP_UNBIND_HELPER,
        self::LDAP_ERRNO_HELPER,
        self::LDAP_ERROR_HELPER,
        self::LDAP_ERR2STR_HELPER,
        self::LDAP_SET_OPTION_HELPER,
        self::LDAP_GET_OPTION_HELPER,
        self::LDAP_GET_OPTION_VALUE_HELPER,
        self::LDAP_START_TLS_HELPER,
        self::LDAP_SET_REBIND_PROC_HELPER,
    ];

    /** @var list<string> */
    private const RESULT_HELPERS = [
        self::LDAP_COMPARE_HELPER,
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
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_connect',
            'ldap_connect_bridge_entry',
            [$strPtr, $i64, $i64],
            $valuePtr,
            self::LDAP_CONNECT_HELPER,
            self::DN_HELPER_PATH,
            self::DN_HELPERS,
            '#32000'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_link_register',
            'ldap_link_register_bridge_entry',
            [$i64],
            $context->getTypeFromString('void'),
            self::LDAP_LINK_REGISTER_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32001'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_bind',
            'ldap_bind_bridge_entry',
            [$i64, $strPtr, $strPtr, $i64, $i64],
            $context->getTypeFromString('int1'),
            self::LDAP_BIND_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32001'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_bind_ext',
            'ldap_bind_ext_bridge_entry',
            [$i64, $strPtr, $strPtr, $i64, $i64],
            $valuePtr,
            self::LDAP_BIND_EXT_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32146'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_sasl_bind',
            'ldap_sasl_bind_bridge_entry',
            [$i64, $strPtr, $strPtr, $strPtr, $strPtr, $strPtr, $strPtr, $strPtr, $i64],
            $context->getTypeFromString('int1'),
            self::LDAP_SASL_BIND_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32147'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_unbind',
            'ldap_unbind_bridge_entry',
            [$i64],
            $context->getTypeFromString('int1'),
            self::LDAP_UNBIND_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32002'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_errno',
            'ldap_errno_bridge_entry',
            [$i64],
            $i64,
            self::LDAP_ERRNO_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32106'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_error',
            'ldap_error_bridge_entry',
            [$i64],
            $strPtr,
            self::LDAP_ERROR_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32106'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_err2str',
            'ldap_err2str_bridge_entry',
            [$i64],
            $strPtr,
            self::LDAP_ERR2STR_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32106'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_set_option',
            'ldap_set_option_bridge_entry',
            [$i64, $i64, $i64, $i64, $i64],
            $context->getTypeFromString('int1'),
            self::LDAP_SET_OPTION_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32107'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_get_option',
            'ldap_get_option_bridge_entry',
            [$i64, $i64, $i64],
            $i64,
            self::LDAP_GET_OPTION_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32107'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_get_option_value',
            'ldap_get_option_value_bridge_entry',
            [],
            $i64,
            self::LDAP_GET_OPTION_VALUE_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32107'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_start_tls',
            'ldap_start_tls_bridge_entry',
            [$i64],
            $context->getTypeFromString('int1'),
            self::LDAP_START_TLS_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32109'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_set_rebind_proc',
            'ldap_set_rebind_proc_bridge_entry',
            [$i64],
            $context->getTypeFromString('int1'),
            self::LDAP_SET_REBIND_PROC_HELPER,
            self::LINK_HELPER_PATH,
            self::LINK_HELPERS,
            '#32148'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_ldap_compare',
            'ldap_compare_bridge_entry',
            [$i64, $strPtr, $strPtr, $strPtr],
            $valuePtr,
            self::LDAP_COMPARE_HELPER,
            self::RESULT_HELPER_PATH,
            self::RESULT_HELPERS,
            '#32121'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
