<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Type;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Compile lib/VM/*JitHelper.php into the active JIT module (#10311).
 */
final class JitVmHelperLink
{
    /**
     * @param list<string> $compiledHelpers fully-qualified logical callee names
     */
    public static function ensureCompiled(
        Context $context,
        string $relativeHelperPath,
        array $compiledHelpers,
        string $compileLabel
    ): void {
        $missing = false;
        foreach ($compiledHelpers as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__).$relativeHelperPath;
        $basename = \basename($path);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $basename, $compileLabel): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), $basename);
            if (null === $block) {
                throw new \LogicException($basename.' parseAndCompile failed ('.$compileLabel.')');
            }
            $jit = new \PHPCompiler\JIT($context);
            $jit->compile($block);
        });
        foreach ($compiledHelpers as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT ('.$compileLabel.')');
            }
        }
    }

    public static function lookupCompiled(Context $context, string $logical, string $issueTag): LlvmFunction
    {
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after VM helper compile ('.$issueTag.')');
        }

        return $fn;
    }

    /**
     * @param list<Type> $paramTypes
     */
    public static function ensureBridge(
        Context $context,
        string $abiName,
        string $entryBlockName,
        array $paramTypes,
        Type $returnType,
        string $helperLogical,
        string $relativeHelperPath,
        array $compiledHelpers,
        string $issueTag
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (self::bridgeEntryComplete($probe)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureCompiled($context, $relativeHelperPath, $compiledHelpers, $issueTag);

        $helperFn = self::lookupCompiled($context, $helperLogical, $issueTag);
        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = self::bridgeEntryForEmit($fn, $entryBlockName);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $abiParam = $fn->getParam($i);
            $helperTy = $helperFn->getParam($i)->typeOf();
            $args[] = JitNestedHelperCoerce::coerceArgForHelper($context, $abiParam, $helperTy);
        }
        $result = $context->builder->call($helperFn, ...$args);
        $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $returnType);
        $context->builder->returnValue($ret);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function bridgeEntryComplete(?LlvmFunction $probe): bool
    {
        if (null === $probe || 0 === $probe->countBasicBlocks()) {
            return false;
        }
        try {
            $blocks = $probe->getBasicBlocks();
            $entry = $blocks[0] ?? null;

            return null !== $entry && null !== $entry->getTerminator();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function bridgeEntryForEmit(LlvmFunction $fn, string $entryBlockName): \PHPLLVM\BasicBlock
    {
        try {
            $blocks = $fn->getBasicBlocks();
            $entry = $blocks[0] ?? null;
            if (null !== $entry && null === $entry->getTerminator()) {
                return $entry;
            }
        } catch (\Throwable) {
        }

        return $fn->appendBasicBlock($entryBlockName);
    }
}
