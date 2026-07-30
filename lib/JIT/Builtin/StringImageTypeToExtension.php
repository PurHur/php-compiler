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
 * JIT/AOT link for __compiler_image_type_to_extension via ImageTypeToExtensionJitHelper PHP (#14851, #25443).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringDateTime #25433).
 * Replaces lookup LLVM in JitImageTypeToExtension.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmImage}.
 * php-src: ext/standard/image.c — PHP_FUNCTION(image_type_to_extension)
 */
final class StringImageTypeToExtension
{
    private const HELPER_PATH = '/ext/standard/ImageTypeToExtensionJitHelper.php';

    private const LOOKUP_HELPER = 'PHPCompiler\\ext\\standard\\ImageTypeToExtensionJitHelper::lookupArgv';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\ImageTypeToExtensionJitHelper::lastString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOOKUP_HELPER,
        self::LAST_STRING,
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
        $probe = $context->module->getNamedFunction('__compiler_image_type_to_extension');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

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
        $abiName = '__compiler_image_type_to_extension';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');

        $ft = $context->context->functionType($voidTy, false, $i64, $i8, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('imgext_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $imageType = $fn->getParam(0);
        $includeDotI8 = $fn->getParam(1);
        $out = $fn->getParam(2);
        $includeDotBool = $context->builder->icmp(
            Builder::INT_NE,
            $includeDotI8,
            $i8->constInt(0, false)
        );

        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOOKUP_HELPER),
            [
                $imageType,
                $includeDotBool,
            ]
        );
        $tagI32 = $context->builder->trunc(
            JitNestedHelperCoerce::coerceHelperScalarResult($context, $tag, $i32),
            $i32
        );
        $isFalse = $context->builder->icmp(
            Builder::INT_EQ,
            $tagI32,
            $i32->constInt(\PHPCompiler\ext\standard\ImageTypeToExtensionJitHelper::TAG_FALSE, false)
        );
        $falseBb = BasicBlockHelper::append($context, 'imgext_bridge_false');
        $okBb = BasicBlockHelper::append($context, 'imgext_bridge_ok');
        $doneBb = BasicBlockHelper::append($context, 'imgext_bridge_done');
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25443');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#25443'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringImageTypeToExtension bridge (#14851/#25443)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
