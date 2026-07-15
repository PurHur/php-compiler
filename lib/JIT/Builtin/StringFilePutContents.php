<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPCompiler\ext\standard\JitFilePutContentsKernel;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_file_put_contents via FilePutContentsJitHelper PHP (#15310, #19294).
 *
 * Embed / non-user-script: {@see FilePutContentsJitHelper} via compile helper.
 * User-script standalone AOT: thin {@see JitFilePutContentsKernel} libc fopen/fwrite — nested
 * helper TUs skip __init__ under PHP_COMPILER_AOT_USER_SCRIPT (#16075).
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

    private const KERNEL_ENTRY = 'fpc_kernel_entry';

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementUserScriptKernel($context);

            return;
        }

        self::implementPhpBridge($context, $probe);
    }

    private static function implementPhpBridge(Context $context, ?LlvmFunction $probe): void
    {
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

    private static function implementUserScriptKernel(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        LibcExtern::register($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i64, false, $strPtr, $strPtr, $i64)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitFilePutContentsKernel::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
