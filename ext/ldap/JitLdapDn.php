<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\LdapRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ldap_dn2ufn() / ldap_explode_dn() (#22212). */
final class JitLdapDn
{
    /** @param list<JITVariable> $args */
    public static function invokeDn2ufn(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_dn2ufn() expects exactly 1 argument, %d given',
                $argc
            ));
        }

        $dnLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null !== $dnLit) {
            return self::materializeDn2ufn($context, $dnLit);
        }

        LdapRuntime::ensureLinked($context);
        $dn = JitStringBuiltinArg::lower($context, $args[0], 'ldap_dn2ufn', 0, 'dn');

        return $context->builder->call(
            $context->lookupFunction('__compiler_ldap_dn2ufn'),
            $dn
        );
    }

    /** @param list<JITVariable> $args */
    public static function invokeExplodeDn(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_explode_dn() expects exactly 2 arguments, %d given',
                $argc
            ));
        }

        $dnLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        $withLit = self::compileTimeLong($args[1]);
        if (null !== $dnLit && null !== $withLit) {
            return self::materializeExplode($context, $dnLit, $withLit);
        }

        LdapRuntime::ensureLinked($context);
        $dn = JitStringBuiltinArg::lower($context, $args[0], 'ldap_explode_dn', 0, 'dn');
        if (null !== $withLit) {
            $with = $context->getTypeFromString('int64')->constInt($withLit, false);
        } else {
            $with = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'ldap_explode_dn', 2, 'with_attrib');
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_ldap_explode_dn'),
            $dn,
            $with
        );
    }

    private static function materializeDn2ufn(Context $context, string $dn): Value
    {
        $result = VmLdapDn::dn2ufn($dn);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $result) {
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return $ptr;
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->builder->load($context->constantStringFromString($result))
        );

        return $ptr;
    }

    private static function materializeExplode(Context $context, string $dn, int $withAttrib): Value
    {
        $result = VmLdapDn::explodeDn($dn, $withAttrib);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $result) {
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return $ptr;
        }
        $ht = VmLdapDn::toHashTable($result);
        $cacheKey = 'ldap_explode_dn:'.md5($dn)."\0".$withAttrib;
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $ptr;
    }

    private static function compileTimeLong(JITVariable $arg): ?int
    {
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }
}
