<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for __compiler_gettimeofday_* via GettimeofdayJitHelper PHP (#13764, #13804).
 *
 * Thin {@see JitVmHelperLink} glue; SSOT {@see \PHPCompiler\ext\standard\VmDate}.
 * php-src: ext/standard/microtimers.c — PHP_FUNCTION(gettimeofday)
 */
final class StringGettimeofday
{
    private const HELPER_PATH = '/ext/standard/GettimeofdayJitHelper.php';

    private const ARRAY_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::gettimeofdayArray';

    private const FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::gettimeofdayFloat';

    private const SEC_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::wallClockSec';

    private const USEC_MASKED_HELPER = 'PHPCompiler\\ext\\standard\\GettimeofdayJitHelper::wallClockUsecMasked';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ARRAY_HELPER,
        self::FLOAT_HELPER,
        self::SEC_HELPER,
        self::USEC_MASKED_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_gettimeofday_array',
        '__compiler_gettimeofday_float',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $arrayProbe = $context->module->getNamedFunction('__compiler_gettimeofday_array');
        if (null !== $arrayProbe && $arrayProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $doubleTy = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_gettimeofday_float',
            'gettimeofday_float_bridge_entry',
            [],
            $doubleTy,
            self::FLOAT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13804'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_gettimeofday_array',
            'gettimeofday_array_bridge_entry',
            [],
            $htPtr,
            self::ARRAY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13804'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_gettimeofday_sec',
            'gettimeofday_sec_bridge_entry',
            [],
            $i64,
            self::SEC_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13804'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_gettimeofday_usec_masked',
            'gettimeofday_usec_masked_bridge_entry',
            [$i32],
            $i64,
            self::USEC_MASKED_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13804'
        );
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /**
     * Wall-clock parts shared by uniqid() lowering (tv_sec, tv_usec % $usecMod).
     *
     * @return array{0: Value, 1: Value} i32 sec and masked usec
     */
    public static function readSecUsec(Context $context, int $usecMod = 0): array
    {
        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $sec = $context->builder->call($context->lookupFunction('__compiler_gettimeofday_sec'));
        $sec32 = $context->builder->truncOrBitCast($sec, $i32);
        $usecModArg = $i32->constInt(max(0, $usecMod), false);
        $usec = $context->builder->call(
            $context->lookupFunction('__compiler_gettimeofday_usec_masked'),
            $usecModArg
        );
        $usec32 = $context->builder->truncOrBitCast($usec, $i32);

        return [$sec32, $usec32];
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringGettimeofday bridge (#13804)');
            }
            $context->registerFunction($name, $fn);
        }
        foreach (['__compiler_gettimeofday_sec', '__compiler_gettimeofday_usec_masked'] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }
}
