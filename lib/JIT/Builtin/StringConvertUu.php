<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_convert_uu* via ConvertUuJitHelper PHP (#13227).
 *
 * Embed and standalone AOT compile the same PHP bridge; no uuencode LLVM monolith.
 * php-src: ext/standard/uuencode.c
 */
final class StringConvertUu
{
    private const HELPER_PATH = '/ext/standard/ConvertUuJitHelper.php';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::encode';

    private const DECODE_TAG = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::decodeTag';

    private const LAST_STRING = 'PHPCompiler\\ext\\standard\\ConvertUuJitHelper::lastString';

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
        $encodeProbe = $context->module->getNamedFunction('__compiler_convert_uuencode');
        $decodeProbe = $context->module->getNamedFunction('__compiler_convert_uudecode');
        if (null !== $encodeProbe && $encodeProbe->countBasicBlocks() > 0
            && null !== $decodeProbe && $decodeProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementEncodeBridge($context);
        self::implementDecodeBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementEncodeBridge(Context $context): void
    {
        $abiName = '__compiler_convert_uuencode';
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

        $entry = $fn->appendBasicBlock('convert_uu_encode_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ENCODE_HELPER),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_convert_uudecode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

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
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $tag = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::DECODE_TAG),
            [$fn->getParam(0)]
        );
        $tagI32 = $context->builder->trunc($tag, $i32);

        $falseBb = BasicBlockHelper::append($context, 'convert_uu_decode_false');
        $stringBb = BasicBlockHelper::append($context, 'convert_uu_decode_string');
        $doneBb = BasicBlockHelper::append($context, 'convert_uu_decode_done');

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
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ConvertUuJitHelper compile (#13227)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ConvertUuJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ConvertUuJitHelper.php parseAndCompile failed (#13227)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#13227)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringConvertUu bridge (#13227)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
