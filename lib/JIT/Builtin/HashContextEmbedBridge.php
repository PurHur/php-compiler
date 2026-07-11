<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT compile link for HashContextJitHelper (#3357).
 */
final class HashContextEmbedBridge
{
    private const HELPER_PATH = '/ext/hash/HashContextJitHelper.php';

    private const INIT_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::init';

    private const UPDATE_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::update';

    private const MARK_FINAL_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::markFinalized';

    private const FINALIZE_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::finalize';

    private const COPY_HELPER = 'PHPCompiler\\ext\\hash\\HashContextJitHelper::copy';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INIT_HELPER,
        self::UPDATE_HELPER,
        self::FINALIZE_HELPER,
        self::MARK_FINAL_HELPER,
        self::COPY_HELPER,
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
            throw new \LogicException($logical.' missing after HashContextJitHelper compile (#3357)');
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

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'HashContextJitHelper.php');
            if (null === $block) {
                throw new \LogicException('HashContextJitHelper.php parseAndCompile failed (#3357)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#3357)');
            }
        }

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
