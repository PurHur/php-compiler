<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixGeteuidKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_geteuid() (#30767).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixGeteuidJitHelper} via
 * {@see JitVmHelperLink} (posix_getuid #30744 / posix_getppid #30728 shape).
 * NestedJIT leaf: module-local geteuid(2) via {@see JitPosixGeteuidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::geteuid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_geteuid)
 */
final class PosixGeteuidJit
{
    private const HELPER_PATH = '/ext/posix/PosixGeteuidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixGeteuidJitHelper::geteuidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_geteuid';

    private const BRIDGE_ENTRY = 'posix_geteuid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_geteuid() — PHP helper bridge; NestedJIT libc geteuid leaf (#30767).
     *
     * @return Value int64 effective user id
     */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixGeteuidKernel::invoke($context);
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
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#30767'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
