<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_file_put_contents via FilePutContentsJitHelper PHP (#15310).
 *
 * Replaces ~177 LOC inline libc fopen/flock/fwrite LLVM.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::filePutContents()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_stream_ex
 */
final class StringFilePutContents
{
    private const ABI = '__compiler_file_put_contents';

    private const HELPER_PATH = '/ext/standard/FilePutContentsJitHelper.php';

    private const WRITE_HELPER = 'PHPCompiler\\ext\\standard\\FilePutContentsJitHelper::writePathArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::WRITE_HELPER,
    ];

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            StringFilePutContentsLibc::implement($context);

            return;
        }

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction(self::ABI);

        self::ensureJitHelperCompiled($context);

        $entry = $fn->appendBasicBlock('fpc_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower(self::WRITE_HELPER);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException(self::WRITE_HELPER.' missing after FilePutContentsJitHelper compile (#15310)');
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
        $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'FilePutContentsJitHelper.php');
        if (null === $block) {
            throw new \LogicException('FilePutContentsJitHelper.php parseAndCompile failed (#15310)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#15310)');
            }
        }
    }
}
