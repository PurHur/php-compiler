<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for include_path builtins via IncludePathJitHelper PHP (#9245, #20877).
 *
 * Embed + thin standalone AOT: NestedJIT {@see IncludePathJitHelper} /
 * {@see IncludePathResolveJitHelper} via {@see JitVmHelperLink}
 * (Serialize #20773 / getenv #20644 shape — no thin stub fork).
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmIncludePath} / {@see \PHPCompiler\ext\standard\VmFs}
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

    private const GET_BRIDGE_ENTRY = 'include_path_get_bridge_entry';

    private const SET_BRIDGE_ENTRY = 'include_path_set_bridge_entry';

    private const RESTORE_BRIDGE_ENTRY = 'include_path_restore_bridge_entry';

    private const RESOLVE_BRIDGE_ENTRY = 'include_path_resolve_bridge_entry';

    private const INIT_ENTRY = 'include_path_init_noop';

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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        // Thin + embed: publish sg_vm_context before NestedJIT of IncludePathJitHelper (#17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $getProbe = $context->module->getNamedFunction('__compiler_get_include_path');
        if (JitVmHelperLink::hasNamedBridgeEntry($getProbe, self::GET_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }
        if (null !== $getProbe && $getProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureStackHelperCompiled($context);
        self::implementInitNoop($context);
        self::implementGetBridge($context);
        self::implementSetBridge($context);
        self::implementRestoreBridge($context);
        self::ensureResolveHelperCompiled($context);
        self::implementResolveBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
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

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::INIT_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementGetBridge(Context $context): void
    {
        $abiName = '__compiler_get_include_path';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::GET_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
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

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::GET_BRIDGE_ENTRY);
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
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::SET_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
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

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::SET_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
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
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_restore_include_path',
            self::RESTORE_BRIDGE_ENTRY,
            [],
            $context->getTypeFromString('void'),
            self::RESTORE_HELPER,
            self::STACK_HELPER_PATH,
            self::STACK_HELPERS,
            '#20877'
        );
    }

    private static function implementResolveBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_stream_resolve_include_path',
            self::RESOLVE_BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::RESOLVE_HELPER,
            self::RESOLVE_HELPER_PATH,
            self::RESOLVE_HELPERS,
            '#20877'
        );
    }

    private static function stackHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureStackHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#20877');
    }

    private static function ensureStackHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::STACK_HELPER_PATH,
            self::STACK_HELPERS,
            '#20877'
        );
    }

    private static function ensureResolveHelperCompiled(Context $context): void
    {
        self::ensureStackHelperCompiled($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::RESOLVE_HELPER_PATH,
            self::RESOLVE_HELPERS,
            '#20877'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after IncludePathRuntime bridge (#20877)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
