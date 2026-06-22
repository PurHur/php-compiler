<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_strip_tags via StripTagsJitHelper PHP (#9196).
 *
 * JIT/normal modules use compiled PHP SSOT; AOT standalone keeps {@see StringStripTagsStandaloneLlvm}
 * until native link can host compiled VmString helpers reliably.
 */
final class StringStripTags
{
    private const HELPER_PATH = '/ext/standard/StripTagsJitHelper.php';

    private const STRIP_TAGS_HELPER = 'PHPCompiler\\ext\\standard\\StripTagsJitHelper::stripTags';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::STRIP_TAGS_HELPER,
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
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringStripTagsStandaloneLlvm::implement($context);

            return;
        }

        self::implementBridge($context, '__compiler_strip_tags', self::STRIP_TAGS_HELPER, 2);
    }

    private static function implementBridge(
        Context $context,
        string $abiName,
        string $helperLogical,
        int $paramCount
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $params = \array_fill(0, $paramCount, $strPtr);
        $ft = $context->context->functionType($strPtr, false, ...$params);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('strip_tags_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $callArgs = [];
        for ($i = 0; $i < $paramCount; ++$i) {
            $callArgs[] = $fn->getParam($i);
        }
        $result = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            ...$callArgs
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StripTagsJitHelper compile (#9196)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StripTagsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StripTagsJitHelper.php parseAndCompile failed (#9196)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                throw new \LogicException($logical.' was not compiled for JIT (#9196)');
            }
        }
    }
}
