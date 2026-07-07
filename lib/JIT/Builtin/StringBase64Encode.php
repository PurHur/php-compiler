<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_base64_encode via Base64EncodeJitHelper PHP (#17234).
 *
 * Replaces inline LLVM in ext/standard/JitBase64Encode.php on the hot path.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/base64.c — PHP_FUNCTION(base64_encode)
 */
final class StringBase64Encode
{
    private const HELPER_PATH = '/ext/standard/Base64EncodeJitHelper.php';

    private const ENCODE_HELPER = 'PHPCompiler\\ext\\standard\\Base64EncodeJitHelper::encodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_base64_encode',
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
        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StringBase64EncodeLlvm::implement($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_base64_encode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_base64_encode';
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

        $entry = $fn->appendBasicBlock('base64_encode_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::ENCODE_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after Base64EncodeJitHelper compile (#17234)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'Base64EncodeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('Base64EncodeJitHelper.php parseAndCompile failed (#17234)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#17234)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringBase64Encode bridge (#17234)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
