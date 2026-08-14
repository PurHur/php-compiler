<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\posix\JitPosixGetegidKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for posix_getegid() (#30986).
 *
 * User-script AOT + embed: {@see \PHPCompiler\ext\posix\PosixGetegidJitHelper} via
 * {@see JitVmHelperLink} (posix_getgid #30803 / posix_geteuid #30767 shape).
 * NestedJIT leaf: module-local getegid(2) via {@see JitPosixGetegidKernel}
 * (avoids re-entering the helper bridge).
 * SSOT (VM): {@see \PHPCompiler\ext\posix\VmPosix::getegid}.
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getegid)
 */
final class PosixGetegidJit
{
    private const HELPER_PATH = '/ext/posix/PosixGetegidJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\posix\\PosixGetegidJitHelper::getegidArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    private const ABI = '__compiler_posix_getegid';

    private const BRIDGE_ENTRY = 'posix_getegid_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /**
     * posix_getegid() — PHP helper bridge; NestedJIT libc getegid leaf (#30986).
     *
     * @return Value int64 effective group id
     */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitPosixGetegidKernel::invoke($context);
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
            '#30986'
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }
}
