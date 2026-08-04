<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sodium_secretbox* via SodiumJitHelper PHP (#13078, #23519).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ArrayDiffAssoc #23498 / MathFrexp #22575).
 * php-src: ext/sodium/libsodium.c
 */
final class StringSodium
{
    private const HELPER_PATH = '/ext/sodium/SodiumJitHelper.php';
    private const PAD_HELPER_PATH = '/ext/sodium/SodiumPadJitHelper.php';

    private const SECRETBOX_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::secretbox';

    private const SECRETBOX_OPEN_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::secretboxOpen';

    private const AUTH_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::auth';

    private const AUTH_VERIFY_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::authVerify';

    private const STREAM_XOR_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::streamXor';

    private const STREAM_XCHACHA20_XOR_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::streamXchacha20Xor';

    private const MEMCMP_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::memcmp';

    private const COMPARE_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumJitHelper::compare';

    private const PAD_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumPadJitHelper::pad';

    private const UNPAD_HELPER = 'PHPCompiler\\ext\\sodium\\SodiumPadJitHelper::unpad';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SECRETBOX_HELPER,
        self::SECRETBOX_OPEN_HELPER,
        self::AUTH_HELPER,
        self::AUTH_VERIFY_HELPER,
        self::STREAM_XOR_HELPER,
        self::STREAM_XCHACHA20_XOR_HELPER,
        self::MEMCMP_HELPER,
        self::COMPARE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementBridge($context, '__compiler_sodium_secretbox', self::SECRETBOX_HELPER);
        self::implementBridge($context, '__compiler_sodium_secretbox_open', self::SECRETBOX_OPEN_HELPER);
        self::implementAuthBridge($context);
        self::implementAuthVerifyBridge($context);
        self::implementMemcmpBridge($context);
        self::implementCompareBridge($context);
        self::implementPadBridge($context, '__compiler_sodium_pad', self::PAD_HELPER);
        self::implementPadBridge($context, '__compiler_sodium_unpad', self::UNPAD_HELPER);
        self::implementBridge($context, '__compiler_sodium_stream_xor', self::STREAM_XOR_HELPER);
        self::implementBridge($context, '__compiler_sodium_stream_xchacha20_xor', self::STREAM_XCHACHA20_XOR_HELPER);
    }

    public static function invokePadHelper(Context $context, string $name, Value $string, Value $blockSize): Value
    {
        self::ensurePadJitHelperCompiled($context);
        $logical = 'sodium_unpad' === $name ? self::UNPAD_HELPER : self::PAD_HELPER;
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::padHelperFunction($context, $logical),
            [$string, $blockSize]
        );

        return JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
    }

    private static function implementPadBridge(Context $context, string $abiName, string $helper): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $i64)
            );

        $entry = $fn->appendBasicBlock('sodium_pad_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helper),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function implementBridge(Context $context, string $abiName, string $helper): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helper),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function implementAuthBridge(Context $context): void
    {
        $abiName = '__compiler_sodium_auth';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_auth_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::AUTH_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function implementAuthVerifyBridge(Context $context): void
    {
        $abiName = '__compiler_sodium_auth_verify';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $strPtr, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_auth_verify_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::AUTH_VERIFY_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($context->builder->zext($result, $i32));
        $context->registerFunction($abiName, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function implementMemcmpBridge(Context $context): void
    {
        $abiName = '__compiler_sodium_memcmp';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_memcmp_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::MEMCMP_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function implementCompareBridge(Context $context): void
    {
        $abiName = '__compiler_sodium_compare';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('sodium_compare_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::COMPARE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23519');
    }

    private static function padHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensurePadJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27687');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23519'
        );
    }

    private static function ensurePadJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::PAD_HELPER_PATH,
            [self::PAD_HELPER, self::UNPAD_HELPER],
            '#27687'
        );
    }
}
