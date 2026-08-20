<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for utf8_encode()/utf8_decode() via Utf8Latin1JitHelper + VmUtf8Latin1 (#9912, #32879).
 *
 * NestedJIT bundle peer {@see StringConvertUu} / #30811 — solo Utf8Latin1JitHelper NestedJIT
 * (or prelinked helper unit.o) SIGSEGVs under thin AOT when the return is used.
 * Module-local ABI owner (ensureBridge getNamedFunction first): Builtin\Type no longer
 * always-declares empty shells (#32879 / peer #32875) — leftover Type decls mint
 * utf8_encode.1 (#31894 / #32122).
 * SSOT: {@see \PHPCompiler\ext\standard\VmUtf8Latin1} (also via {@see \PHPCompiler\ext\standard\VmString}).
 * php-src: ext/standard/utf8.c — php_utf8_encode, php_utf8_decode
 */
final class StringUtf8Latin1
{
    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        '/ext/standard/VmUtf8Latin1.php',
        '/ext/standard/Utf8Latin1JitHelper.php',
    ];

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\Utf8Latin1JitHelper::encode';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\Utf8Latin1JitHelper::decodeArgv';

    private const ENCODE_ABI = '__compiler_utf8_encode';

    private const DECODE_ABI = '__compiler_utf8_decode';

    private const ENCODE_BRIDGE_ENTRY = 'utf8_encode_bridge_entry';

    private const DECODE_BRIDGE_ENTRY = 'utf8_decode_bridge_entry';

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

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $encodeProbe = $context->module->getNamedFunction(self::ENCODE_ABI);
        $decodeProbe = $context->module->getNamedFunction(self::DECODE_ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($encodeProbe, self::ENCODE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($decodeProbe, self::DECODE_BRIDGE_ENTRY)
        ) {
            $context->registerFunction(self::ENCODE_ABI, $encodeProbe);
            $context->registerFunction(self::DECODE_ABI, $decodeProbe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#32879',
            true
        );
        self::implementBridge(
            $context,
            self::ENCODE_ABI,
            self::ENCODE_BRIDGE_ENTRY,
            self::ENCODE_HELPER
        );
        self::implementBridge(
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

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $entryBlockName,
        string $helperLogical
    ): void {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            $abiName,
            $entryBlockName,
            [$strPtr],
            $strPtr,
            $helperLogical,
            self::HELPER_BUNDLE[1],
            self::COMPILED_HELPERS,
            '#32879',
            true
        );
    }
}
