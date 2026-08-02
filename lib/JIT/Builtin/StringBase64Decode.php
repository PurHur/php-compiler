<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for base64_decode() via Base64JitHelper PHP (#17234, #17249, #18918, #26890).
 *
 * User-script AOT uses HelperRuntimeCache prelinked units (#15889). Peer: StringStrRot13 #26868.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::base64_decode()} (VM); helper is NestedJIT-self-contained.
 * php-src: ext/standard/base64.c — PHP_FUNCTION(base64_decode)
 */
final class StringBase64Decode
{
    private const ABI = '__compiler_base64_decode';

    private const HELPER_PATH = '/ext/standard/Base64JitHelper.php';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\Base64JitHelper::decodeArgv';

    private const BRIDGE_ENTRY = 'base64_decode_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DECODE_HELPER,
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
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::DECODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26890'
        );
    }
}
