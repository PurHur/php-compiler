<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for empty-regex preg_replace via PregEmptyPatternReplaceJitHelper (#11024, #27432).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiledBundle} (peer StringPack #22842 /
 * LateStaticBindingRuntime #27416).
 * SSOT: {@see \PHPCompiler\ext\standard\PregEmptyPatternReplace}.
 * php-src: ext/pcre/php_pcre.c — empty-pattern preg_replace fast path
 */
final class PregEmptyPatternReplaceRuntime
{
    private const HELPER_PATH = '/ext/standard/PregEmptyPatternReplaceJitHelper.php';

    private const CORE_PATH = '/ext/standard/PregEmptyPatternReplace.php';

    /**
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::CORE_PATH,
        self::HELPER_PATH,
    ];

    private const REPLACE_HELPER = 'PHPCompiler\\ext\\standard\\PregEmptyPatternReplaceJitHelper::replace';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REPLACE_HELPER,
    ];

    private const ABI_NAME = 'phpc_preg_replace_empty_pattern';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        self::ensureValueStringHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementReplaceBridge($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementReplaceBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->module->addFunction(
            self::ABI_NAME,
            $context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i64)
        );

        $entry = $fn->appendBasicBlock('preg_empty_replace_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::REPLACE_HELPER),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2),
            $fn->getParam(3)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27432');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        self::ensureValueStringHelpers($context);
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#27432'
        );
    }

    private static function ensureValueStringHelpers(Context $context): void
    {
        foreach (['__string__init', '__string__alloc'] as $name) {
            if (!isset($context->functions[$name])) {
                throw new \LogicException($name.' must be linked before PregEmptyPatternReplaceRuntime (#11024)');
            }
        }
    }
}
