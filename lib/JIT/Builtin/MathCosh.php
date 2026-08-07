<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for cosh() via CoshJitHelper PHP (#15156, #27005, #28446).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (MathSinh #28418 / MathAtanh #28377 shape).
 * NestedJIT no longer needs a libc cosh(3) kernel — helper uses NestedJIT-safe exp.
 * php-src: ext/standard/math.c — PHP_FUNCTION(cosh)
 */
final class MathCosh
{
    private const ABI_COSH = 'phpc_cosh';

    private const HELPER_PATH = '/ext/standard/CoshJitHelper.php';

    private const COSH_HELPER = 'PHPCompiler\\ext\\standard\\CoshJitHelper::coshArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COSH_HELPER,
    ];

    private const BRIDGE_ENTRY = 'cosh_bridge_entry';

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
            $context->lookupFunction(self::ABI_COSH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_COSH);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_COSH, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COSH,
            self::BRIDGE_ENTRY,
            [$double],
            $double,
            self::COSH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28446'
        );
    }
}
