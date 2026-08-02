<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for quoted_printable_encode/decode via QuotPrintJitHelper PHP (#5225, #9910, #24620, #26899).
 *
 * User-script AOT uses HelperRuntimeCache prelinked units (#15889). Peer: StringStrRot13 #26868 /
 * StringSoundex #26882 — {@see JitVmHelperLink::ensureBridge} (typed signature re-localize).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString} (VM); helper is NestedJIT-self-contained.
 * php-src: ext/standard/quot_print.c
 */
final class StringQuotPrint
{
    private const HELPER_PATH = '/ext/standard/QuotPrintJitHelper.php';

    private const ENCODE_ABI = '__compiler_quoted_printable_encode';

    private const DECODE_ABI = '__compiler_quoted_printable_decode';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\QuotPrintJitHelper::encode';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\QuotPrintJitHelper::decode';

    private const ENCODE_BRIDGE_ENTRY = 'quot_print_encode_bridge_entry';

    private const DECODE_BRIDGE_ENTRY = 'quot_print_decode_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_HELPER,
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

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementOne(
            $context,
            self::ENCODE_ABI,
            self::ENCODE_BRIDGE_ENTRY,
            self::ENCODE_HELPER
        );
        self::implementOne(
            $context,
            self::DECODE_ABI,
            self::DECODE_BRIDGE_ENTRY,
            self::DECODE_HELPER
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementOne(
        Context $context,
        string $abi,
        string $bridgeEntry,
        string $helperLogical
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $bridgeEntry)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $bridgeEntry,
            [$strPtr],
            $strPtr,
            $helperLogical,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26899'
        );
    }
}
