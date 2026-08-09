<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_random_bytes via RandomBytesJitHelper PHP (#9149, #21186, #29531).
 *
 * Embed + thin standalone AOT: {@see RandomBytesJitHelper} via {@see JitVmHelperLink}
 * (HashEquals #20469 / Rename #19215 shape — no user-script null stub).
 * Nested helper compile: `@random_bytes` → libc CSPRNG leaf ({@see JitRandomBytesKernel})
 * without re-entering RandomBytesJitHelper — kernel Internal deleted (#29531).
 * SSOT: {@see \PHPCompiler\ext\standard\VmRandomPure}.
 * php-src: ext/standard/random.c — php_random_bytes()
 */
final class StringRandomBytes
{
    private const ABI = '__compiler_random_bytes';

    private const HELPER_PATH = '/ext/standard/RandomBytesJitHelper.php';

    private const RANDOM_BYTES_HELPER = 'PHPCompiler\\ext\\standard\\RandomBytesJitHelper::randomBytes';

    private const BRIDGE_ENTRY = 'rb_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RANDOM_BYTES_HELPER,
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

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$i64],
            $strPtr,
            self::RANDOM_BYTES_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21186'
        );
    }
}
