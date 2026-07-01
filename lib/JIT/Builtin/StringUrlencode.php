<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __string__urlencode / __string__rawurlencode via UrlencodeJitHelper PHP (#14724).
 *
 * Replaces ~312 LOC inline LLVM in StringUrlencode.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/url.c — PHP_FUNCTION(urlencode), PHP_FUNCTION(rawurlencode)
 */
final class StringUrlencode
{
    private const HELPER_PATH = '/ext/standard/UrlencodeJitHelper.php';

    private const URLENCODE_HELPER = 'PHPCompiler\\ext\\standard\\UrlencodeJitHelper::urlencodeArgv';

    private const RAWURLENCODE_HELPER = 'PHPCompiler\\ext\\standard\\UrlencodeJitHelper::rawurlencodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::URLENCODE_HELPER,
        self::RAWURLENCODE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__string__urlencode',
        '__string__rawurlencode',
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
        $probe = $context->module->getNamedFunction('__string__urlencode');
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
        self::implementBridge($context, '__string__urlencode', self::URLENCODE_HELPER);
        self::implementBridge($context, '__string__rawurlencode', self::RAWURLENCODE_HELPER);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context, string $abiName, string $helperLogical): void
    {
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

        $entry = $fn->appendBasicBlock('urlencode_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
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
            throw new \LogicException($logical.' missing after UrlencodeJitHelper compile (#14724)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'UrlencodeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('UrlencodeJitHelper.php parseAndCompile failed (#14724)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#14724)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringUrlencode bridge (#14724)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
