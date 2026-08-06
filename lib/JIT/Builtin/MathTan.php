<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for tan() via TanJitHelper PHP (#15088, #27048, #28226).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathCos #28042 / MathSin #28016 shape).
 * NestedJIT no longer needs a libc tan(3) kernel — helper uses NestedJIT-safe sin/cos Horner.
 * php-src: ext/standard/math.c — PHP_FUNCTION(tan)
 */
final class MathTan
{
    private const ABI_TAN = 'phpc_tan';

    private const HELPER_PATH = '/ext/standard/TanJitHelper.php';

    private const TAN_HELPER = 'PHPCompiler\\ext\\standard\\TanJitHelper::tanArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TAN_HELPER,
    ];

    private const BRIDGE_ENTRY = 'tan_bridge_entry';

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
            $context->lookupFunction(self::ABI_TAN),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_TAN);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_TAN, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TAN,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::TAN_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28226'
        );
    }
}
