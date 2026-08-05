<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_convert_uu* via ConvertUuJitHelper PHP (#13227, #18827, #26898).
 *
 * Helper is NestedJIT-self-contained (peer Hex2bin #27008 / Bin2hex #20452 / Base64 #26890).
 * Encode: __string__* → __string__*. Decode: string|false → __value__ writeString / writeBool
 * (no tag+static lastString — AOT statics were empty under helper-runtime units).
 * php-src: ext/standard/uuencode.c
 */
final class StringConvertUu
{
    private const HELPER_PATH = '/ext/standard/ConvertUuJitHelper.php';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::encode';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::decodeArgv';

    private const ENCODE_BRIDGE_ENTRY = 'convert_uu_encode_bridge_entry';

    private const DECODE_BRIDGE_ENTRY = 'convert_uu_decode_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_HELPER,
        self::DECODE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_convert_uuencode',
        '__compiler_convert_uudecode',
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

        $encodeProbe = $context->module->getNamedFunction('__compiler_convert_uuencode');
        $decodeProbe = $context->module->getNamedFunction('__compiler_convert_uudecode');
        if (JitVmHelperLink::hasNamedBridgeEntry($encodeProbe, self::ENCODE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($decodeProbe, self::DECODE_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, BasicBlockHelper::tryGetInsertBlock($context));

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_convert_uuencode',
            self::ENCODE_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::ENCODE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26898'
        );
        self::implementDecodeBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_convert_uudecode';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::DECODE_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($voidTy, false, $strPtr, $valuePtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::DECODE_BRIDGE_ENTRY);
        $falseBb = $fn->appendBasicBlock('convert_uu_decode_false');
        $stringBb = $fn->appendBasicBlock('convert_uu_decode_string');
        $doneBb = $fn->appendBasicBlock('convert_uu_decode_done');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(1);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DECODE_HELPER),
            [$fn->getParam(0)]
        );
        $isFalse = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isFalse, $falseBb, $stringBb);

        $context->builder->positionAtEnd($falseBb);
        // __value__writeBool ABI is (__value__*, i32) — not i8 (#27008).
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26898');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26898'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringConvertUu bridge (#26898)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $savedInsert): void
    {
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
