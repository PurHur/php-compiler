<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ldexp() via LdexpJitHelper PHP (#15073).
 *
 * Replaces libc `ldexp` LLVM lookup in ext/standard/ldexp.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(ldexp)
 */
final class MathLdexp
{
    private const ABI_LDEXP = 'phpc_ldexp';

    private const HELPER_PATH = '/ext/standard/LdexpJitHelper.php';

    private const LDEXP_HELPER = 'PHPCompiler\\ext\\standard\\LdexpJitHelper::ldexpArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LDEXP_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $exp): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_LDEXP),
            $num,
            $exp
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LDEXP,
            'ldexp_bridge_entry',
            [$double, $i32],
            $double,
            self::LDEXP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15073'
        );
    }
}
