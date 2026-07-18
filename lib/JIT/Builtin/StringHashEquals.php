<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_hash_equals via HashEqualsJitHelper PHP (#9164, #20065, #20469).
 *
 * Embed + thin standalone AOT: {@see HashEqualsJitHelper} via {@see JitVmHelperLink}
 * (Bin2hex #20452 / Addslashes #18391 shape — no hand-written XOR kernel).
 * SSOT: {@see \PHPCompiler\ext\standard\VmHash::equals}.
 * php-src: ext/hash/hash.c — hash_equals()
 */
final class StringHashEquals
{
    private const ABI = '__compiler_hash_equals';

    private const HELPER_PATH = '/ext/standard/HashEqualsJitHelper.php';

    private const EQUALS_HELPER = 'PHPCompiler\\ext\\standard\\HashEqualsJitHelper::equals';

    private const BRIDGE_ENTRY = 'hash_equals_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EQUALS_HELPER,
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
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
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
        $strPtr = $context->getTypeFromString('__string__*');
        // ABI is i32 (Type.php + JitHash::equals icmp); helper returns i1 — coerceBridgeResult zexts.
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $i32,
            self::EQUALS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20469'
        );
    }
}
