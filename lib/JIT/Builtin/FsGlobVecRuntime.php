<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed link for glob()/scandir() via FsGlobJitHelper PHP (#11515).
 *
 * Standalone AOT keeps libc vec LLVM in {@see FsGlobVecStandaloneLlvm}.
 * php-src: ext/standard/dir.c
 */
final class FsGlobVecRuntime
{
    private const HELPER_PATH = '/ext/standard/FsGlobJitHelper.php';

    public const GLOB_HELPER = 'PHPCompiler\\ext\\standard\\FsGlobJitHelper::globArgv';

    public const SCANDIR_HELPER = 'PHPCompiler\\ext\\standard\\FsGlobJitHelper::scandirArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GLOB_HELPER,
        self::SCANDIR_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after FsGlobJitHelper compile (#11515)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'FsGlobJitHelper.php');
            if (null === $block) {
                throw new \LogicException('FsGlobJitHelper.php parseAndCompile failed (#11515)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#11515)');
            }
        }
    }
}
