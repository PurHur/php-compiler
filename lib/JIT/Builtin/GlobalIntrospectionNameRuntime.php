<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT bridge for global introspection name normalization (#12176).
 */
final class GlobalIntrospectionNameRuntime
{
    private const HELPER_PATH = '/ext/standard/GlobalIntrospectionNameJitHelper.php';

    private const NORMALIZE_HELPER = 'PHPCompiler\\ext\\standard\\GlobalIntrospectionNameJitHelper::normalize';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NORMALIZE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function normalizeString(Context $context, $nameStr)
    {
        self::ensureLinked($context);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::NORMALIZE_HELPER),
            [$nameStr]
        );

        return JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_normalize_global_introspection_name');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_normalize_global_introspection_name', $probe);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_normalize_global_introspection_name';
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $probe = $context->module->getNamedFunction($abiName);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('gin_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $name = $fn->getParam(0);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::NORMALIZE_HELPER),
            [$name]
        );
        $normalized = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $context->builder->returnValue($normalized);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GlobalIntrospectionNameJitHelper compile (#12176)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GlobalIntrospectionNameJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GlobalIntrospectionNameJitHelper.php parseAndCompile failed (#12176)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
    }
}
