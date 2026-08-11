<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitTimeKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for time() via TimeJitHelper PHP (#30332).
 *
 * Embed + thin standalone AOT: {@see TimeJitHelper} via {@see JitVmHelperLink}
 * (microtime #29405 / gethostname #29364 shape).
 * Nested helper compile: `@time` → {@see JitTimeKernel} thin libc time(2) leaf
 * without re-entering TimeJitHelper (former always-on JitDate libc lookup).
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmDate::time()}.
 * php-src: ext/date/php_date.c — PHP_FUNCTION(time)
 */
final class StringTime
{
    private const HELPER_PATH = '/ext/standard/TimeJitHelper.php';

    private const TIME_HELPER = 'PHPCompiler\\ext\\standard\\TimeJitHelper::timeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::TIME_HELPER,
    ];

    private const ABI = '__compiler_time';

    private const BRIDGE_ENTRY = 'time_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value int64 — Unix timestamp seconds */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitTimeKernel::invoke($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI));
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [],
            $i64,
            self::TIME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30332'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
