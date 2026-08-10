<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for clamp() via ClampJitHelper PHP.
 *
 * NestedJIT-safe peel lives in {@see \PHPCompiler\ext\standard\ClampJitHelper}
 * (no VmMath clamp helper / is_nan / Variable spaceship — #29730 / Ldexp #29578).
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmMath::clamp()}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(clamp)
 */
final class MathClamp
{
    private const ABI = 'phpc_clamp';

    private const HELPER_PATH = '/ext/standard/ClampJitHelper.php';

    private const CLAMP_HELPER = 'PHPCompiler\\ext\\standard\\ClampJitHelper::clampArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CLAMP_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $valuePtr,
        Value $minPtr,
        Value $maxPtr
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $valuePtr,
            $minPtr,
            $maxPtr
        );
    }

    private static function implement(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'clamp_bridge_entry',
            [$valuePtr, $valuePtr, $valuePtr],
            $valuePtr,
            self::CLAMP_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'clamp'
        );
    }
}
