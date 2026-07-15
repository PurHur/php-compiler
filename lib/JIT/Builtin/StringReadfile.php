<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPCompiler\ext\standard\JitReadfileKernel;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_readfile via ReadfileJitHelper PHP (#9188, #19311).
 *
 * Embed / non-user-script: {@see ReadfileJitHelper} via compile helper.
 * User-script standalone AOT: thin {@see JitReadfileKernel} libc open/read/write —
 * nested helper TUs skip __init__ under PHP_COMPILER_AOT_USER_SCRIPT (#16075).
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::readfile()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_passthru
 */
final class StringReadfile
{
    private const ABI = '__compiler_readfile';

    private const HELPER_PATH = '/ext/standard/ReadfileJitHelper.php';

    private const READFILE_HELPER = 'PHPCompiler\\ext\\standard\\ReadfileJitHelper::readfile';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::READFILE_HELPER,
    ];

    private const KERNEL_ENTRY = 'rf_kernel_entry';

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

        $entry = $fn->appendBasicBlock('readfile_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context),
            $fn->getParam(0)
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
                $context->context->functionType($i64, false, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitReadfileKernel::emitBody($context, $fn);
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
