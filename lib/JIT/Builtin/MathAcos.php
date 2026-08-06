<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for acos() via AcosJitHelper PHP (#15141, #27048, #28276).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathAsin #28263 / MathSin #28016 shape).
 * NestedJIT no longer needs a libc acos(3) kernel — helper uses NestedJIT-safe π/2−asin poly.
 * php-src: ext/standard/math.c — PHP_FUNCTION(acos)
 */
final class MathAcos
{
    private const ABI_ACOS = 'phpc_acos';

    private const HELPER_PATH = '/ext/standard/AcosJitHelper.php';

    private const ACOS_HELPER = 'PHPCompiler\\ext\\standard\\AcosJitHelper::acosArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ACOS_HELPER,
    ];

    private const BRIDGE_ENTRY = 'acos_bridge_entry';

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
            $context->lookupFunction(self::ABI_ACOS),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_ACOS);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_ACOS, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ACOS,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::ACOS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28276'
        );
    }
}
