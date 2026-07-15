<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_convert_uu* via ConvertUuJitHelper PHP (#13227, #18827).
 *
 * User-script AOT uses HelperRuntimeCache prelinked units (#15889) instead of LLVM defer.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/uuencode.c
 */
final class StringConvertUu
{
    private const HELPER_PATH = '/ext/standard/ConvertUuJitHelper.php';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::encode';

    private const DECODE_TAG = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::decodeTag';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::lastString';

    private const ENCODE_BRIDGE_ENTRY = 'convert_uu_encode_bridge_entry';

    private const TAG_FALSE = 0;

    private const TAG_STRING = 1;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_HELPER,
        self::DECODE_TAG,
        self::LAST_STRING,
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
        if (null !== $encodeProbe && $encodeProbe->countBasicBlocks() > 0
            && null !== $decodeProbe && $decodeProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, BasicBlockHelper::tryGetInsertBlock($context));

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

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
            '#18827'
        );
        self::implementDecodeBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_convert_uudecode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18827');

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($voidTy, false, $strPtr, $valuePtr)
            );

        $entry = $fn->appendBasicBlock('convert_uu_decode_bridge_entry');
        $falseBb = $fn->appendBasicBlock('convert_uu_decode_false');
        $stringBb = $fn->appendBasicBlock('convert_uu_decode_string');
        $doneBb = $fn->appendBasicBlock('convert_uu_decode_done');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DECODE_TAG),
            [$fn->getParam(0)]
        );
        $tagI32 = $context->builder->trunc($tag, $i32);

        $isFalse = $context->builder->icmp(Builder::INT_EQ, $tagI32, $i32->constInt(self::TAG_FALSE, false));
        $context->builder->branchIf($isFalse, $falseBb, $stringBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $strResult = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_STRING),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strResult)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        return JitVmHelperLink::lookupCompiled($context, $logical, '#18827');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringConvertUu bridge (#18827)');
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
