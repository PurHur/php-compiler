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
 * JIT/AOT link for __compiler_image_type_to_extension via ImageTypeToExtensionJitHelper PHP (#14851, #25443, #28314).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringHex2bin #27008).
 * Bridge maps string|false → __value__ writeString / writeBool (i32 ABI). No tag + static stash.
 * NestedJIT helper is self-contained (peer Hex2bin #27008); VM path still uses {@see \PHPCompiler\ext\standard\VmImage}.
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_extension)
 */
final class StringImageTypeToExtension
{
    private const HELPER_PATH = '/ext/standard/ImageTypeToExtensionJitHelper.php';

    private const LOOKUP_HELPER = 'PHPCompiler\\ext\\standard\\ImageTypeToExtensionJitHelper::imageTypeToExtensionArgv';

    private const BRIDGE_ENTRY = 'imgext_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOOKUP_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_image_type_to_extension',
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

        $probe = $context->module->getNamedFunction('__compiler_image_type_to_extension');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
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
        $abiName = '__compiler_image_type_to_extension';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $i64, $i8, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        // Append bridge BBs on $fn before NestedJIT helpers move the insert block.
        $falseBb = $fn->appendBasicBlock('imgext_bridge_false');
        $okBb = $fn->appendBasicBlock('imgext_bridge_ok');
        $doneBb = $fn->appendBasicBlock('imgext_bridge_done');
        $context->builder->positionAtEnd($entry);

        $imageType = $fn->getParam(0);
        $includeDotI8 = $fn->getParam(1);
        $out = $fn->getParam(2);
        $includeDotBool = $context->builder->icmp(
            Builder::INT_NE,
            $includeDotI8,
            $i8->constInt(0, false)
        );

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOOKUP_HELPER),
            [
                $imageType,
                $includeDotBool,
            ]
        );
        $isFalse = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isFalse, $falseBb, $okBb);

        $context->builder->positionAtEnd($falseBb);
        // __value__writeBool ABI is (__value__*, i32) — not i8 (#27008 / #28314).
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#28314');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28314'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringImageTypeToExtension bridge (#14851/#28314)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
