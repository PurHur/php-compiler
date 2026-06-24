<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_get_meta_tags via MetaTagsJitHelper PHP (#9338).
 *
 * Replaces ~650-line LLVM HTML walker; SSOT {@see \PHPCompiler\ext\standard\VmMetaTags}.
 * php-src: ext/standard/php_meta_tags.c — PHP_FUNCTION(get_meta_tags)
 */
final class MetaTagsRuntime
{
    private const ABI_NAME = '__compiler_get_meta_tags';

    private const HELPER_PATH = '/ext/standard/MetaTagsJitHelper.php';

    private const GET_META_TAGS_HELPER = 'PHPCompiler\\ext\\standard\\MetaTagsJitHelper::getMetaTags';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GET_META_TAGS_HELPER,
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
        self::implementGetMetaTagsBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetMetaTagsBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $ft = $context->context->functionType($htPtr, false, $strPtr, $i1);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('meta_tags_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $ht = $context->builder->call(
            self::helperFunction($context, self::GET_META_TAGS_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($ht);
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after MetaTagsJitHelper compile (#9338)');
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'MetaTagsJitHelper.php');
        if (null === $block) {
            throw new \LogicException('MetaTagsJitHelper.php parseAndCompile failed (#9338)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9338)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after MetaTagsRuntime bridge (#9338)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
