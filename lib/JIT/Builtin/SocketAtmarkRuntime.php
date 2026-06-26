<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for SocketAtmarkJitHelper (#9215).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_atmark)
 */
final class SocketAtmarkRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketAtmarkJitHelper.php';

    private const ATMARK_HELPER = 'PHPCompiler\\ext\\sockets\\SocketAtmarkJitHelper::atmarkForHandle';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ATMARK_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (self::helperCompiled($context)) {
            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureLinked($context);
        $lc = \strtolower(self::ATMARK_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::ATMARK_HELPER.' missing after SocketAtmarkJitHelper compile (#9215)');
        }

        return $fn;
    }

    private static function helperCompiled(Context $context): bool
    {
        return isset($context->functions[\strtolower(self::ATMARK_HELPER)]);
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        if (self::helperCompiled($context)) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        $realPath = \realpath($path) ?: $path;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $realPath): void {
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SocketAtmarkJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('SocketAtmarkJitHelper.php parseAndCompile failed (#9215)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            });
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        if (!self::helperCompiled($context)) {
            throw new \LogicException(self::ATMARK_HELPER.' was not compiled for JIT (#9215)');
        }
    }
}
