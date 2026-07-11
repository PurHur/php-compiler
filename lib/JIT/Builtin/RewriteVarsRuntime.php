<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for output_add_rewrite_var / output_reset_rewrite_vars via OutputRewriteVarsJitHelper PHP (#9477, #9753).
 *
 * JIT embed and AOT standalone compile {@see OutputRewriteVarsJitHelper} static storage.
 * php-src: ext/standard/url.c — PHP_FUNCTION(output_add_rewrite_var), output_reset_rewrite_vars.
 * VM SSOT: {@see \PHPCompiler\Web\ResponseContext}.
 */
final class RewriteVarsRuntime
{
    private const HELPER_PATH = '/ext/standard/OutputRewriteVarsJitHelper.php';

    private const URL_REWRITER_PATH = '/ext/standard/VmUrlRewriterOb.php';

    private const ADD_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::add';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::reset';

    private const ENSURE_URL_REWRITER = 'PHPCompiler\\ext\\standard\\VmUrlRewriterOb::ensureRegistered';

    private const RESET_URL_REWRITER = 'PHPCompiler\\ext\\standard\\VmUrlRewriterOb::resetState';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENSURE_URL_REWRITER,
        self::RESET_URL_REWRITER,
        self::ADD_HELPER,
        self::RESET_HELPER,
    ];

    /** @var list<string> */
    private const COMPILE_PATHS = [
        self::URL_REWRITER_PATH,
        self::HELPER_PATH,
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
            throw new \LogicException($logical.' missing after OutputRewriteVarsJitHelper compile (#9477)');
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
        $root = \dirname(__DIR__, 3);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $root): void {
            foreach (self::COMPILE_PATHS as $relPath) {
                $path = $root.$relPath;
                $realPath = \realpath($path) ?: $path;
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), \basename($path));
                if (null === $block) {
                    throw new \LogicException(\basename($path).' parseAndCompile failed (#9477)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            }
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9477)');
            }
        }
    }
}
