<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_readfile via ReadfileJitHelper PHP (#9188).
 *
 * Replaces ~150-line libc open/read/write LLVM loop. SSOT: {@see \PHPCompiler\ext\standard\VmFs::readfile}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_passthru
 */
final class StringReadfile
{
    private const HELPER_PATH = '/ext/standard/ReadfileJitHelper.php';

    private const READFILE_HELPER = 'PHPCompiler\\ext\\standard\\ReadfileJitHelper::readfile';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::READFILE_HELPER,
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
        $probe = $context->module->getNamedFunction('__compiler_readfile');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_readfile', $probe);

            return;
        }

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction('__compiler_readfile');

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('readfile_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction('__compiler_readfile', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::READFILE_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::READFILE_HELPER.' missing after ReadfileJitHelper compile (#9188)');
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ReadfileJitHelper.php');
        if (null === $block) {
            throw new \LogicException('ReadfileJitHelper.php parseAndCompile failed (#9188)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9188)');
            }
        }
    }
}
