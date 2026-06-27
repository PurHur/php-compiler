<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin as JitBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for include_path builtins via IncludePathJitHelper PHP (#9245, #12801).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\IncludePathJitHelper}; thin LLVM bridges
 * forward the ABI. VM SSOT: {@see \PHPCompiler\ext\standard\VmIncludePath} / {@see \PHPCompiler\ext\standard\VmFs}
 * php-src: ext/standard/basic_functions.c — php_get_include_path / php_set_include_path
 * php-src: ext/standard/streams.c — php_stream_resolve_include_path
 */
final class IncludePathRuntime
{
    private const STACK_HELPER_PATH = '/ext/standard/IncludePathJitHelper.php';

    private const RESOLVE_HELPER_PATH = '/ext/standard/IncludePathResolveJitHelper.php';

    private const GET_HELPER = 'PHPCompiler\\ext\\standard\\IncludePathJitHelper::get';

    private const PUSH_HELPER = 'PHPCompiler\\ext\\standard\\IncludePathJitHelper::push';

    private const RESTORE_HELPER = 'PHPCompiler\\ext\\standard\\IncludePathJitHelper::restore';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\IncludePathResolveJitHelper::resolveJit';

    /** @var list<string> */
    private const STACK_HELPERS = [
        self::GET_HELPER,
        self::PUSH_HELPER,
        self::RESTORE_HELPER,
    ];

    /** @var list<string> */
    private const RESOLVE_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_include_path_init',
        '__compiler_get_include_path',
        '__compiler_set_include_path',
        '__compiler_restore_include_path',
        '__compiler_stream_resolve_include_path',
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
        $probe = $context->module->getNamedFunction('__compiler_get_include_path');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = self::captureInsertBlock($context);

        if (JitBuiltin::LOAD_TYPE_STANDALONE === $context->loadType) {
            // Standalone AOT init cannot nested-JIT compile PHP helpers (#12801 regression, #1492).
            IncludePathStandaloneLlvm::implement($context);
        } else {
            self::ensureStackHelperCompiled($context);
            self::implementInitNoop($context);
            self::implementGetBridge($context);
            self::implementSetBridge($context);
            self::implementRestoreBridge($context);
            self::ensureResolveHelperCompiled($context);
            self::implementResolveBridge($context);
        }
        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $savedBlock);
    }

    public static function implementInitNoop(Context $context): void
    {
        $abiName = '__compiler_include_path_init';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_init_noop');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetBridge(Context $context): void
    {
        $abiName = '__compiler_get_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_get_bridge');
        $context->builder->positionAtEnd($entry);
        $strRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::stackHelperFunction($context, self::GET_HELPER),
            []
        );
        $str = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $strRaw);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $fn->getParam(0),
            $str
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSetBridge(Context $context): void
    {
        $abiName = '__compiler_set_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $strPtr, $valPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_set_bridge');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $newLen = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $fn->getParam(0)
        );
        $emptyBlock = BasicBlockHelper::append($context, 'include_path_set_empty');
        $pushBlock = BasicBlockHelper::append($context, 'include_path_set_push');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $newLen, $i64->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBlock, $pushBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $fn->getParam(1),
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($pushBlock);
        $oldStrRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::stackHelperFunction($context, self::PUSH_HELPER),
            [$fn->getParam(0)]
        );
        $oldStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $oldStrRaw);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $fn->getParam(1),
            $oldStr
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementRestoreBridge(Context $context): void
    {
        $abiName = '__compiler_restore_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_restore_bridge');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(self::stackHelperFunction($context, self::RESTORE_HELPER));
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementResolveBridge(Context $context): void
    {
        $abiName = '__compiler_stream_resolve_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('include_path_resolve_bridge');
        $context->builder->positionAtEnd($entry);
        $resultRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::resolveHelperFunction($context, self::RESOLVE_HELPER),
            [$fn->getParam(0)]
        );
        $result = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $resultRaw);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function stackHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureStackHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after IncludePathJitHelper compile (#9245)');
        }

        return $fn;
    }

    private static function resolveHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureResolveHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after IncludePathResolveJitHelper compile (#9245)');
        }

        return $fn;
    }

    private static function ensureStackHelperCompiled(Context $context): void
    {
        self::ensureHelpersCompiled($context, self::STACK_HELPER_PATH, self::STACK_HELPERS);
    }

    private static function ensureResolveHelperCompiled(Context $context): void
    {
        self::ensureStackHelperCompiled($context);
        self::ensureHelpersCompiled($context, self::RESOLVE_HELPER_PATH, self::RESOLVE_HELPERS);
    }

    /**
     * @param list<string> $compiledHelpers
     */
    private static function ensureHelpersCompiled(Context $context, string $relativePath, array $compiledHelpers): void
    {
        $missing = false;
        foreach ($compiledHelpers as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).$relativePath;
        $basename = \basename($path);
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $basename): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), $basename);
            if (null === $block) {
                throw new \LogicException($basename.' parseAndCompile failed (#9245)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach ($compiledHelpers as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9245)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after IncludePathRuntime bridge (#9245)');
            }
            $context->registerFunction($name, $fn);
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
