<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hex2bin via Hex2binJitHelper PHP (#14627, #22746).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringUtf8Latin1 #22701).
 * Replaces ~253-line LLVM in ext/standard/JitHex2bin.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(hex2bin)
 */
final class StringHex2bin
{
    private const HELPER_PATH = '/ext/standard/Hex2binJitHelper.php';

    private const HEX2BIN_HELPER = 'PHPCompiler\\ext\\standard\\Hex2binJitHelper::hex2binArgv';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\Hex2binJitHelper::lastString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HEX2BIN_HELPER,
        self::LAST_STRING,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_hex2bin',
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
        $probe = $context->module->getNamedFunction('__compiler_hex2bin');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringTriggerError::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_hex2bin';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $strPtr, $i8, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('hex2bin_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $strictI8 = $fn->getParam(1);
        $out = $fn->getParam(2);
        $strictBool = $context->builder->icmp(
            Builder::INT_NE,
            $strictI8,
            $i8->constInt(0, false)
        );

        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::HEX2BIN_HELPER),
            [$data, $strictBool]
        );
        $tagI32 = $context->builder->trunc(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $tag, $i32),
            $i32
        );
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $tagI32,
            $i32->constInt(\PHPCompiler\ext\standard\Hex2binJitHelper::TAG_FALSE, false)
        );
        $falseBb = BasicBlockHelper::append($context, 'hex2bin_false');
        $okBb = BasicBlockHelper::append($context, 'hex2bin_ok');
        $doneBb = BasicBlockHelper::append($context, 'hex2bin_done');
        $context->builder->branchIf($isFalse, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i8->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $resultStr = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LAST_STRING),
            []
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $resultStr, $strPtr)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22746');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22746'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHex2bin bridge (#14627)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
