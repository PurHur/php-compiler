<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for log() via LogJitHelper PHP (#15117).
 *
 * Replaces libc `log` LLVM lookup in ext/standard/log.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMath}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(log)
 */
final class MathLog
{
    private const ABI_LOG = 'phpc_log';

    private const HELPER_PATH = '/ext/standard/LogJitHelper.php';

    private const LOG_HELPER = 'PHPCompiler\\ext\\standard\\LogJitHelper::logArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOG_HELPER,
    ];

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
            $context->lookupFunction(self::ABI_LOG),
            $num
        );
    }

    private static function implement(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_LOG,
            'log_bridge_entry',
            [$double],
            $double,
            self::LOG_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15117'
        );
    }
}
