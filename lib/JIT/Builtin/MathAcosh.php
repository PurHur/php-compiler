<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitAcoshKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for acosh() via AcoshJitHelper PHP (#15221, #27058).
 *
 * Embed + thin standalone AOT: {@see AcoshJitHelper} via {@see JitVmHelperLink}
 * (Ceil/Sqrt #20664 / #27005 cosh shape — double via helper result coerce).
 * Nested helper compile: libc leaf without re-entering AcoshJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(acosh)
 */
final class MathAcosh
{
    private const ABI_ACOSH = 'phpc_sinh';

    private const HELPER_PATH = '/ext/standard/AcoshJitHelper.php';

    private const ACOSH_HELPER = 'PHPCompiler\\ext\\standard\\AcoshJitHelper::sinhArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ACOSH_HELPER,
    ];

    private const BRIDGE_ENTRY = 'sinh_bridge_entry';

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
            return JitAcoshKernel::invoke($context, $num);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_ACOSH),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

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
            '#27058'
        );
    }
}
