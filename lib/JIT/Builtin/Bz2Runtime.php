<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_bzcompress/__compiler_bzdecompress via Bz2JitHelper PHP (#8868, #12827, #16853, #20117).
 *
 * Always {@see JitVmHelperLink} → {@see \PHPCompiler\ext\bz2\Bz2JitHelper}
 * (no user-script NestedJIT defer early-return — thin/user-script AOT must still link bridges).
 * SSOT: {@see \PHPCompiler\ext\bz2\VmBz2Native}.
 * php-src: ext/bz2/bz2.c
 */
final class Bz2Runtime
{
    private const HELPER_PATH = '/ext/bz2/Bz2JitHelper.php';

    private const COMPRESS_HELPER = 'PHPCompiler\\ext\\bz2\\Bz2JitHelper::compressArgv';

    private const DECOMPRESS_HELPER = 'PHPCompiler\\ext\\bz2\\Bz2JitHelper::decompressArgv';

    private const COMPRESS_BRIDGE_ENTRY = 'bz2_compress_bridge_entry';

    private const DECOMPRESS_BRIDGE_ENTRY = 'bz2_decompress_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPRESS_HELPER,
        self::DECOMPRESS_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_bzcompress',
        '__compiler_bzdecompress',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $compressProbe = $context->module->getNamedFunction('__compiler_bzcompress');
        $decompressProbe = $context->module->getNamedFunction('__compiler_bzdecompress');
        if (null !== $compressProbe
            && JitVmHelperLink::hasNamedBridgeEntry($compressProbe, self::COMPRESS_BRIDGE_ENTRY)
            && null !== $decompressProbe
            && JitVmHelperLink::hasNamedBridgeEntry($decompressProbe, self::DECOMPRESS_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementCompressBridge($context);
        self::implementDecompressBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementCompressBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $probe = $context->module->getNamedFunction('__compiler_bzcompress');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::COMPRESS_BRIDGE_ENTRY)) {
            $context->registerFunction('__compiler_bzcompress', $probe);

            return;
        }

        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_bzcompress',
            self::COMPRESS_BRIDGE_ENTRY,
            [$strPtr, $i64, $i64],
            $strPtr,
            self::COMPRESS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16853'
        );
    }

    private static function implementDecompressBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $probe = $context->module->getNamedFunction('__compiler_bzdecompress');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::DECOMPRESS_BRIDGE_ENTRY)) {
            $context->registerFunction('__compiler_bzdecompress', $probe);

            return;
        }

        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_bzdecompress',
            self::DECOMPRESS_BRIDGE_ENTRY,
            [$strPtr, $i64],
            $strPtr,
            self::DECOMPRESS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#16853'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after Bz2Runtime bridge (#8868)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
