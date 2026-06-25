<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for get_browser() via GetBrowserJitHelper PHP (#11172).
 *
 * php-src: ext/standard/browscap.c — PHP_FUNCTION(get_browser)
 */
final class GetBrowserRuntime
{
    private const ABI_NAME = '__compiler_get_browser_browscap_configured';

    private const HELPER_PATH = '/ext/standard/GetBrowserJitHelper.php';

    private const BROWSCAP_CONFIGURED_HELPER = 'PHPCompiler\\ext\\standard\\GetBrowserJitHelper::browscapConfigured';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BROWSCAP_CONFIGURED_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
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
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('get_browser_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $configured = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::BROWSCAP_CONFIGURED_HELPER),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $configured, $i32)
        );
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GetBrowserJitHelper compile (#11172)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GetBrowserJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GetBrowserJitHelper.php parseAndCompile failed (#11172)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#11172)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after GetBrowserRuntime bridge (#11172)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
