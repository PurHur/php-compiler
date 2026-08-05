<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for fmod() via FmodJitHelper PHP (#15072, #26994, #27838).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (floor/ceil #27650 / deg2rad #27400 shape).
 * NestedJIT no longer needs a libc fmod(3) kernel — helper inlines NestedJIT-safe reduction.
 * php-src: ext/standard/math.c — PHP_FUNCTION(fmod)
 */
final class MathFmod
{
    private const ABI_FMOD = 'phpc_fmod';

    private const HELPER_PATH = '/ext/standard/FmodJitHelper.php';

    private const FMOD_HELPER = 'PHPCompiler\\ext\\standard\\FmodJitHelper::fmodArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FMOD_HELPER,
    ];

    private const BRIDGE_ENTRY = 'fmod_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num1, Value $num2): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_FMOD),
            $num1,
            $num2
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_FMOD);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_FMOD, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FMOD,
            self::BRIDGE_ENTRY,
            [$double, $double],
            $double,
            self::FMOD_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27838'
        );
    }
}
