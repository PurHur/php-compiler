<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for output_add_rewrite_var / output_reset_rewrite_vars via OutputRewriteVarsJitHelper PHP (#9753).
 *
 * JIT embed and AOT standalone both call compiled {@see OutputRewriteVarsJitHelper} static storage.
 * php-src: ext/standard/url.c — PHP_FUNCTION(output_add_rewrite_var), output_reset_rewrite_vars.
 * VM SSOT: {@see \PHPCompiler\Web\ResponseContext}.
 */
final class RewriteVarsRuntime
{
    private const HELPER_PATH = '/ext/standard/OutputRewriteVarsJitHelper.php';

    private const ADD_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::add';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::reset';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ADD_HELPER,
        self::RESET_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function emitAdd(Context $context, Value $nameStr, Value $valueStr): Value
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::ADD_HELPER),
            $nameStr,
            $valueStr
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    public static function emitReset(Context $context): Value
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(self::helperFunction($context, self::RESET_HELPER));
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after OutputRewriteVarsJitHelper compile (#9753)');
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
        $realPath = \realpath($path) ?: $path;
        $savedBuilder = $context->builder;
        $savedActive = $context->activeFunction;
        $restoreBlock = self::captureInsertBlock($context);
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'OutputRewriteVarsJitHelper.php');
            if (null === $block) {
                throw new \LogicException('OutputRewriteVarsJitHelper.php parseAndCompile failed (#9753)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
            $context->markJitIncludedFileCompiled($realPath);
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
                throw new \LogicException($lc.' was not compiled for JIT (#9753)');
            }
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
