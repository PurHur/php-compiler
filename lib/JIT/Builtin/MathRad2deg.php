<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitRad2degKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for rad2deg() via Rad2degJitHelper PHP (#15143, #26996 / #27006).
 *
 * Embed + thin standalone AOT: {@see Rad2degJitHelper} via {@see JitVmHelperLink}.
 * Nested helper compile: fmul leaf without re-entering Rad2degJitHelper / VmMath.
 * php-src: ext/standard/math.c — PHP_FUNCTION(rad2deg)
 */
final class MathRad2deg
{
    private const ABI_RAD2DEG = 'phpc_rad2deg';

    private const HELPER_PATH = '/ext/standard/Rad2degJitHelper.php';

    private const RAD2DEG_HELPER = 'PHPCompiler\\ext\\standard\\Rad2degJitHelper::rad2degArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RAD2DEG_HELPER,
    ];

    private const BRIDGE_ENTRY = 'rad2deg_bridge_entry';

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
        if (NestedJitCompileScope::isActive()) {
            return JitRad2degKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_RAD2DEG),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_RAD2DEG);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_RAD2DEG, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_RAD2DEG,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::RAD2DEG_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26996'
        );
    }
}
