<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for nextafter() via NextafterJitHelper PHP (#15062, #19259, #20034, #20664, #28716).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathFpow #28674 / MathLog10 #28642 shape).
 * NestedJIT no longer needs an LLVM bitcast kernel — helper uses NestedJIT-safe ULP peel.
 * php-src: libc nextafter(3) semantics (userland nextafter is a php-src phantom — #28565).
 */
final class MathNextafter
{
    private const ABI_NEXTAFTER = 'phpc_nextafter';

    private const HELPER_PATH = '/ext/standard/NextafterJitHelper.php';

    private const NEXTAFTER_HELPER = 'PHPCompiler\\ext\\standard\\NextafterJitHelper::nextafterArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NEXTAFTER_HELPER,
    ];

    private const BRIDGE_ENTRY = 'nextafter_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $next): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NEXTAFTER),
            $num,
            $next
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NEXTAFTER);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_NEXTAFTER, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NEXTAFTER,
            self::BRIDGE_ENTRY,
            [$double, $double],
            $double,
            self::NEXTAFTER_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28716'
        );
    }
}
