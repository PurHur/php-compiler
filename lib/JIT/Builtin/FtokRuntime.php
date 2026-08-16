<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitFtokKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ftok() (#31478, re-#9585 / #27389).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\standard\FtokJitHelper} via
 * {@see JitVmHelperLink} (posix_getpid #30696 / getmypid #30623 shape).
 * NestedJIT leaf: module-local ftok(3) via {@see JitFtokKernel}
 * (avoids re-entering the helper bridge / former VmFtok ExternalMethod stub #27389).
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmFtok}.
 * php-src: ext/standard/ftok.c — PHP_FUNCTION(ftok)
 */
final class FtokRuntime
{
    private const HELPER_PATH = '/ext/standard/FtokJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\standard\\FtokJitHelper::ftokArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_ftok';

    private const BRIDGE_ENTRY = 'ftok_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * ftok() — PHP helper bridge; NestedJIT libc ftok(3) leaf (#31478).
     *
     * @param Value $pathStr __string__*
     * @param Value $projId  i32 project id byte
     *
     * @return Value i64 — System V key, or -1 on failure
     */
    public static function invoke(Context $context, Value $pathStr, Value $projId): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitFtokKernel::invoke($context, $pathStr, $projId);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $pathStr,
            $projId
        );
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

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27389 / #27088).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i32],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31478'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
