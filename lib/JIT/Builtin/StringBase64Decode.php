<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\BasicBlock;

/**
 * JIT/AOT link for __compiler_base64_decode via Base64JitHelper PHP (#17234, #17249).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/base64.c — PHP_FUNCTION(base64_decode)
 */
final class StringBase64Decode
{
    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_base64_decode',
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
        $savedBlock = self::captureInsertBlock($context);
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StringBase64DecodeLlvm::implement($context);
            self::restoreInsertBlock($context, $savedBlock);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_base64_decode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $savedBlock);

            return;
        }

        Base64JitLink::ensureJitHelpersCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_base64_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('base64_decode_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            Base64JitLink::helperFunction($context, Base64JitLink::decodeHelper()),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringBase64Decode bridge (#17249)');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $savedBlock): void
    {
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
