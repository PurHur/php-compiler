<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\Builtin\LdapRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ldap_escape() via LdapEscapeJitHelper (#6352, #18173). */
final class JitLdapEscape
{
    /** @param list<JITVariable> $args */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_escape() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }

        $valueLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        $ignoreLit = null;
        if ($argc >= 2) {
            $ignoreLit = JitStringArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        }
        $flagsLit = 0;
        if (3 === $argc) {
            $parsedFlags = self::compileTimeLong($args[2]);
            if (null === $parsedFlags) {
                $flagsLit = null;
            } else {
                $flagsLit = $parsedFlags;
            }
        }

        if (null !== $valueLit && (1 === $argc || null !== $ignoreLit) && null !== $flagsLit) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmLdapEscape::escape($valueLit, $ignoreLit ?? '', $flagsLit)
                )
            );
        }

        LdapRuntime::ensureLinked($context);
        $value = JitStringBuiltinArg::lower($context, $args[0], 'ldap_escape', 0, 'value');
        if (1 === $argc) {
            $ignore = $context->builder->load($context->constantStringFromString(''));
        } elseif (null !== $ignoreLit) {
            $ignore = $context->builder->load($context->constantStringFromString($ignoreLit));
        } else {
            $ignore = JitStringBuiltinArg::lower($context, $args[1], 'ldap_escape', 1, 'ignore');
        }

        if (3 === $argc) {
            if (null !== $flagsLit) {
                $flags = $context->getTypeFromString('int64')->constInt($flagsLit, false);
            } else {
                $flags = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'ldap_escape', 3, 'flags');
            }
        } else {
            $flags = $context->getTypeFromString('int64')->constInt(0, false);
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_ldap_escape'),
            $value,
            $ignore,
            $flags
        );
    }

    private static function compileTimeLong(JITVariable $arg): ?int
    {
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }
}
