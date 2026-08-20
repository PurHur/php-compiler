<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for utf8_encode()/utf8_decode() via Utf8Latin1JitHelper PHP (#9912, #22701, #32879).
 *
 * User-script AOT uses HelperRuntimeCache prelinked units (#15889). Peer: StringQuotPrint #26899 —
 * {@see JitVmHelperLink::ensureBridge} (typed signature re-localize).
 * Module-local ABI owner (ensureBridge getNamedFunction first): Builtin\Type no longer
 * always-declares empty shells for utf8_encode/decode (#32879) — leftover Type decls mint
 * utf8_encode.1 (#31894 / #32122). Type::initialize still ensureLinked.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/utf8.c — php_utf8_encode, php_utf8_decode
 */
final class StringUtf8Latin1
{
    private const HELPER_PATH = '/ext/standard/Utf8Latin1JitHelper.php';

    private const ENCODE_ABI = '__compiler_utf8_encode';

    private const DECODE_ABI = '__compiler_utf8_decode';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\Utf8Latin1JitHelper::encode';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\Utf8Latin1JitHelper::decode';

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

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementOne($context, self::ENCODE_ABI, self::ENCODE_BRIDGE_ENTRY, self::ENCODE_HELPER);
        self::implementOne($context, self::DECODE_ABI, self::DECODE_BRIDGE_ENTRY, self::DECODE_HELPER);
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
            '#22701'
        );
    }
}
