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
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), $basename);
            if (null === $block) {
                throw new \LogicException($basename.' parseAndCompile failed ('.$compileLabel.')');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
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
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        self::ensureCompiled($context, $relativeHelperPath, $compiledHelpers, $issueTag);

        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock($entryBlockName);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = $fn->getParam($i);
        }
        $result = $context->builder->call(
            self::lookupCompiled($context, $helperLogical, $issueTag),
            ...$args
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }
}
