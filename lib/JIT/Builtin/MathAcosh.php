<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for acosh() via AcoshJitHelper PHP (#15221, #27058, #28331).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathAcos #28276 / MathAsin #28263 shape).
 * NestedJIT no longer needs a libc acosh(3) kernel — helper uses NestedJIT-safe log+sqrt.
 * php-src: ext/standard/math.c — PHP_FUNCTION(acosh)
 */
final class MathAcosh
{
    private const ABI_ACOSH = 'phpc_acosh';

    private const HELPER_PATH = '/ext/standard/AcoshJitHelper.php';

    private const ACOSH_HELPER = 'PHPCompiler\\ext\\standard\\AcoshJitHelper::acoshArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ACOSH_HELPER,
    ];

    private const BRIDGE_ENTRY = 'acosh_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ACOSH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ACOSH);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ACOSH, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ACOSH,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::ACOSH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28331'
        );
    }
}
