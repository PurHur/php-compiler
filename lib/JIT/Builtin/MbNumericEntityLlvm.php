<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * User-script standalone AOT bridges for mb_encode/decode_numericentity() (#18035).
 *
 * Nested {@see MbNumericEntityJitHelper} compile segfaults after minimal standalone init
 * (#15407). Emit thin ABI bridges that call prelinked helper-runtime units (#15889).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_numericentity)
 */
final class MbNumericEntityLlvm
{
    private const ABI_ENCODE4 = '__compiler_mb_encode_numericentity4';
    private const ABI_DECODE4 = '__compiler_mb_decode_numericentity4';

    private const HELPER_PATH = '/ext/mbstring/MbNumericEntityJitHelper.php';

    private const ENCODE4_HELPER = 'PHPCompiler\\ext\\mbstring\\MbNumericEntityJitHelper::encode4';
    private const DECODE4_HELPER = 'PHPCompiler\\ext\\mbstring\\MbNumericEntityJitHelper::decode4';

    private const ENCODE4_BRIDGE_ENTRY = 'mb_encode_numericentity4_bridge_entry';
    private const DECODE4_BRIDGE_ENTRY = 'mb_decode_numericentity4_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE4_HELPER,
        self::DECODE4_HELPER,
    ];

    public static function implement(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementEncode4Bridge($context);
        self::implementDecode4Bridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementEncode4Bridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ENCODE4,
            self::ENCODE4_BRIDGE_ENTRY,
            [$strPtr, $i64, $i64, $i64, $i64, $strPtr, $i8],
            $strPtr,
            self::ENCODE4_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18035'
        );
    }

    private static function implementDecode4Bridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DECODE4,
            self::DECODE4_BRIDGE_ENTRY,
            [$strPtr, $i64, $i64, $i64, $i64, $strPtr],
            $strPtr,
            self::DECODE4_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18035'
        );
    }
}
