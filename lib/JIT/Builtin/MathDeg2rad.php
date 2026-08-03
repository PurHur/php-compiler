<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitDeg2radKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for deg2rad() via Deg2radJitHelper PHP (#15143, #26996).
 *
 * Embed + thin standalone AOT: {@see Deg2radJitHelper} via {@see JitVmHelperLink}.
 * Nested helper compile: fmul leaf without re-entering Deg2radJitHelper / VmMath.
 * php-src: ext/standard/math.c — PHP_FUNCTION(deg2rad)
 */
final class MathDeg2rad
{
    private const ABI_DEG2RAD = 'phpc_deg2rad';

    private const HELPER_PATH = '/ext/standard/Deg2radJitHelper.php';

    private const DEG2RAD_HELPER = 'PHPCompiler\\ext\\standard\\Deg2radJitHelper::deg2radArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DEG2RAD_HELPER,
    ];

    private const BRIDGE_ENTRY = 'deg2rad_bridge_entry';

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
            return JitDeg2radKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_DEG2RAD),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_DEG2RAD);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_DEG2RAD, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DEG2RAD,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::DEG2RAD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26996'
        );
    }
}
