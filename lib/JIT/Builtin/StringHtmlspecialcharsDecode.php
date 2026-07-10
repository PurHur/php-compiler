<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __string__htmlspecialchars_decode via HtmlspecialcharsDecodeJitHelper PHP (#14820).
 *
 * Replaces ~320 LOC inline LLVM in StringHtmlspecialcharsDecode.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString::htmlspecialchars_decode()}.
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars_decode)
 */
final class StringHtmlspecialcharsDecode
{
    private const HELPER_PATH = '/ext/standard/HtmlspecialcharsDecodeJitHelper.php';

    private const HTMLSPECIALCHARS_DECODE_HELPER = 'PHPCompiler\\ext\\standard\\HtmlspecialcharsDecodeJitHelper::htmlspecialcharsDecodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HTMLSPECIALCHARS_DECODE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__string__htmlspecialchars_decode',
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

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StringHtmlspecialcharsDecodeLlvm::implement($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__string__htmlspecialchars_decode');
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
        $abiName = '__string__htmlspecialchars_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('htmlspecialchars_decode_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::HTMLSPECIALCHARS_DECODE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
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
            throw new \LogicException($logical.' missing after HtmlspecialcharsDecodeJitHelper compile (#14820)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'HtmlspecialcharsDecodeJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HtmlspecialcharsDecodeJitHelper.php parseAndCompile failed (#14820)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#14820)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHtmlspecialcharsDecode bridge (#14820)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
