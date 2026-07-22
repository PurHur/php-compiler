<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for ldap_escape / ldap_dn2ufn / ldap_explode_dn (#6352, #18173, #22212).
 *
 * php-src: ext/ldap/ldap.c
 */
final class LdapRuntime
{
    private const ESCAPE_HELPER_PATH = '/ext/ldap/LdapEscapeJitHelper.php';

    private const DN_HELPER_PATH = '/ext/ldap/LdapDnJitHelper.php';

    private const LDAP_ESCAPE_HELPER = 'PHPCompiler\\ext\\ldap\\LdapEscapeJitHelper::ldapEscape';

    private const LDAP_DN2UFN_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::dn2ufn';

    private const LDAP_EXPLODE_DN_HELPER = 'PHPCompiler\\ext\\ldap\\LdapDnJitHelper::explodeDn';

    /** @var list<string> */
    private const ESCAPE_HELPERS = [
        self::LDAP_ESCAPE_HELPER,
    ];

    /** @var list<string> */
    private const DN_HELPERS = [
        self::LDAP_DN2UFN_HELPER,
        self::LDAP_EXPLODE_DN_HELPER,
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

        self::implementEscapeBridge($context);

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

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

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementEscapeBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_ldap_escape');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_ldap_escape', $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_ldap_escape', $ft);

        self::ensureEscapeHelperCompiled($context);

        $entry = $fn->appendBasicBlock('ldap_escape_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::escapeHelperFunction($context, self::LDAP_ESCAPE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_ldap_escape', $fn);
    }

    private static function escapeHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureEscapeHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after LdapEscapeJitHelper compile (#18173)');
        }

        return $fn;
    }

    private static function ensureEscapeHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::ESCAPE_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::ESCAPE_HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'LdapEscapeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('LdapEscapeJitHelper.php parseAndCompile failed (#18173)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::ESCAPE_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#18173)');
            }
        }
    }
}
