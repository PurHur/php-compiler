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
 * JIT/AOT link for __compiler_hex2bin via Hex2binJitHelper PHP (#14627, #22746, #27008).
 *
 * Helper is NestedJIT-self-contained (peer Bin2hex #20452 / Base64Decode #26890). Bridge maps
 * string|false → __value__ writeString / writeBool (i32 ABI). No tag+static lastString.
 * php-src: ext/standard/string.c — PHP_FUNCTION(hex2bin)
 */
final class StringHex2bin
{
    private const HELPER_PATH = '/ext/standard/Hex2binJitHelper.php';

    private const HEX2BIN_HELPER = 'PHPCompiler\\ext\\standard\\Hex2binJitHelper::hex2binArgv';

    private const BRIDGE_ENTRY = 'hex2bin_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HEX2BIN_HELPER,
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_hex2bin');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_hex2bin';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
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

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $falseBb = $fn->appendBasicBlock('hex2bin_false');
        $okBb = $fn->appendBasicBlock('hex2bin_ok');
        $doneBb = $fn->appendBasicBlock('hex2bin_done');
        $context->builder->positionAtEnd($entry);

        $data = $fn->getParam(0);
        $strictI8 = $fn->getParam(1);
        $out = $fn->getParam(2);
        $strictBool = $context->builder->icmp(
            Builder::INT_NE,
            $strictI8,
            $i8->constInt(0, false)
        );

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::HEX2BIN_HELPER),
            [$data, $strictBool]
        );
        $isFalse = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isFalse, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        // __value__writeBool ABI is (__value__*, i32) — not i8 (#27008).
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27008');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27008'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHex2bin bridge (#27008)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
