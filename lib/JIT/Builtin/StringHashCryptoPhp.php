<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for hash crypto via HashCryptoJitHelper PHP (#9164).
 *
 * Replaces ~2.8k-line StringHashCryptoNativeJit LLVM; SSOT {@see \PHPCompiler\ext\standard\VmHash}.
 * php-src: ext/standard/hash.c, ext/standard/hash_hmac.c
 */
final class StringHashCryptoPhp
{
    private const HELPER_PATH = '/ext/standard/HashCryptoJitHelper.php';

    private const HASH_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hash';

    private const HMAC_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashHmac';

    private const PBKDF2_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashPbkdf2';

    private const HKDF_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashHkdf';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HASH_HELPER,
        self::HMAC_HELPER,
        self::PBKDF2_HELPER,
        self::HKDF_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_hash',
        '__compiler_hash_hmac',
        '__compiler_hash_pbkdf2',
        '__compiler_hash_hkdf',
    ];

    public static function implement(Context $context, bool $forceNested = false): void
    {
        $probe = $context->module->getNamedFunction('__compiler_hash');
        if (!$forceNested && null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementHashBridge($context);
        self::implementHmacBridge($context);
        self::implementPbkdf2Bridge($context);
        self::implementHkdfBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementHashBridge(Context $context): void
    {
        $abiName = '__compiler_hash';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i32)
            );

        $entry = $fn->appendBasicBlock('hc_hash_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = self::callHelperBridge($context, $fn, self::HASH_HELPER);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementHmacBridge(Context $context): void
    {
        $abiName = '__compiler_hash_hmac';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i32)
            );

        $entry = $fn->appendBasicBlock('hc_hmac_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = self::callHelperBridge($context, $fn, self::HMAC_HELPER);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPbkdf2Bridge(Context $context): void
    {
        $abiName = '__compiler_hash_pbkdf2';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64, $i64, $i32)
            );

        $entry = $fn->appendBasicBlock('hc_pbkdf2_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = self::callHelperBridge($context, $fn, self::PBKDF2_HELPER);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementHkdfBridge(Context $context): void
    {
        $abiName = '__compiler_hash_hkdf';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i64, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('hc_hkdf_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = self::callHelperBridge($context, $fn, self::HKDF_HELPER);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function callHelperBridge(Context $context, LlvmFunction $abiFn, string $helperLogical): Value
    {
        $helperFn = self::helperFunction($context, $helperLogical);
        $args = [];
        for ($i = 0, $n = $abiFn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $abiFn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }

        $raw = $context->builder->call($helperFn, ...$args);

        return JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after HashCryptoJitHelper compile (#9164)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'HashCryptoJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HashCryptoJitHelper.php parseAndCompile failed (#9164)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9164)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHashCryptoPhp bridge (#9164)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
