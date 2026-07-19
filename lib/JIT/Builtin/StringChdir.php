<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitChdirKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for chdir() via ChdirJitHelper PHP (#21147).
 *
 * Embed + thin standalone AOT: {@see ChdirJitHelper} via {@see JitVmHelperLink}
 * (Rename #20603 / Unlink #19186 shape — no thin libc ABI fork).
 * Nested helper compile: libc leaf without re-entering ChdirJitHelper.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::chdir()}.
 * php-src: ext/standard/dir.c — PHP_FUNCTION(chdir)
 */
final class StringChdir
{
    private const ABI = '__phpc_jit_chdir';

    private const HELPER_PATH = '/ext/standard/ChdirJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\ChdirJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'chdir_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $path): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitChdirKernel::invoke($context, $path);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $path);
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

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $i1,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21147'
        );
    }
}
