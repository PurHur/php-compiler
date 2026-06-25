<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for empty-regex preg_replace via PregEmptyPatternReplaceJitHelper (#11024).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\PregEmptyPatternReplace}.
 */
final class PregEmptyPatternReplaceRuntime
{
    private const HELPER_PATH = '/ext/standard/PregEmptyPatternReplaceJitHelper.php';

    private const CORE_PATH = '/ext/standard/PregEmptyPatternReplace.php';

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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PregEmptyPatternReplaceJitHelper compile (#11024)');
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

        self::ensureValueStringHelpers($context);

        $runtime = $context->runtime;
        $root = \dirname(__DIR__, 3);
        $paths = [
            $root.self::CORE_PATH,
            $root.self::HELPER_PATH,
        ];
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $jit = new JIT($context);
            foreach ($paths as $path) {
                $realPath = \realpath($path) ?: $path;
                if ($context->hasJitIncludedFileCompiled($realPath)) {
                    continue;
                }
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), \basename($path));
                if (null === $block) {
                    throw new \LogicException(\basename($path).' parseAndCompile failed (#11024)');
                }
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            }
        } finally {
            $context->builder = $savedBuilder;
            self::restoreInsertBlock($context, $restoreBlock);
            $context->activeFunction = $savedActive;
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#11024)');
            }
        }
    }

    private static function ensureValueStringHelpers(Context $context): void
    {
        foreach (['__string__init', '__string__alloc'] as $name) {
            if (!isset($context->functions[$name])) {
                throw new \LogicException($name.' must be linked before PregEmptyPatternReplaceRuntime (#11024)');
            }
        }
    }

    /** @return array{0: BasicBlock|null, 1: BasicBlock|null} */
    private static function captureInsertBlock(Context $context): array
    {
        $fn = $context->activeFunction;
        $bb = $context->builder->getInsertBlock();

        return [$fn, $bb];
    }

    /** @param array{0: BasicBlock|null, 1: BasicBlock|null} $restore */
    private static function restoreInsertBlock(Context $context, array $restore): void
    {
        [$fn, $bb] = $restore;
        if (null !== $fn) {
            $context->activeFunction = $fn;
        }
        if (null !== $fn && null !== $bb) {
            $context->builder->positionAtEnd($bb);
        }
    }
}
