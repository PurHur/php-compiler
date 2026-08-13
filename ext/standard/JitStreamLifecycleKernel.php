<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Builtin\StreamLibcHandleRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed + thin standalone link for stream lifecycle via StreamLifecycleJitHelper PHP (#9442, #20966).
 *
 * Embed: NestedJIT {@see StreamLifecycleJitHelper} / {@see StreamLibcHandleJitHelper}.
 * Thin user-script AOT: {@see __compiler_is_resource} probes LLVM {@see StreamGlobalsJit}
 * handle table (same slots {@see JitStreamIoKernel} fopen fills) — NestedJIT helpers never
 * see those slots (#27186).
 * SSOT: {@see VmFs}, {@see StreamLifecycleJitHelper}, {@see StreamGlobalsJit}
 * php-src: ext/standard/streamsfuncs.c
 */
final class JitStreamLifecycleKernel
{
    private const HELPER_PATH = '/ext/standard/StreamLifecycleJitHelper.php';

    /**
     * Share NestedJIT registry with StreamIoJitHelper (#23777).
     * JitMemoryStreamHelper must be in the same bundle as JitOpenStreamHandles (#25299).
     *
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        '/ext/standard/JitMemoryStreamHelper.php',
        '/ext/standard/JitOpenStreamHandles.php',
        self::HELPER_PATH,
    ];

    private const LIBC_HELPER_PATH = '/ext/standard/StreamLibcHandleJitHelper.php';

    private const IS_RESOURCE = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::isResourceArgv';

    private const FCLOSE = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::fcloseArgv';

    private const FEOF = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::feofArgv';

    private const FFLUSH = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::fflushArgv';

    private const PCLOSE = 'PHPCompiler\\ext\\standard\\StreamLifecycleJitHelper::pcloseArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IS_RESOURCE,
        self::FCLOSE,
        self::FEOF,
        self::FFLUSH,
        self::PCLOSE,
    ];

    /** @var list<string> */
    private const LIBC_HELPERS = [
        'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::registerFromPtr',
        'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::markPopen',
        'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::resolvePtr',
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_resource',
        '__compiler_fclose',
        '__compiler_feof',
        '__compiler_fflush',
        '__compiler_pclose',
    ];

    /** @var array<string, string> */
    private const ABI_TO_HELPER = [
        '__compiler_is_resource' => self::IS_RESOURCE,
        '__compiler_fclose' => self::FCLOSE,
        '__compiler_feof' => self::FEOF,
        '__compiler_fflush' => self::FFLUSH,
        '__compiler_pclose' => self::PCLOSE,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Real fclose/feof bridges for user-script stream lowering (#9142, #20966). */
    public static function ensureLinkedForUserScriptLowering(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        // Thin + embed: publish sg_vm_context before NestedJIT of StreamLifecycleJitHelper (#17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        if ($context->isThinStandaloneAotMain()) {
            self::implementThinStandaloneBridges($context);

            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_is_resource');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::implementRealBridges($context);
    }

    /**
     * Thin AOT: is_resource reads {@see StreamGlobalsJit} slots; other lifecycle ABI still NestedJIT.
     */
    private static function implementThinStandaloneBridges(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        StreamGlobalsJit::implementThinIsResource($context);

        self::ensureJitHelperCompiled($context);
        foreach (self::ABI_TO_HELPER as $abi => $helper) {
            if ('__compiler_is_resource' === $abi) {
                continue;
            }
            if ('__compiler_fclose' === $abi || '__compiler_pclose' === $abi) {
                self::implementCloseBridge($context, $abi, $helper);
                continue;
            }
            self::implementIfMissing($context, $abi, $helper);
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementRealBridges(Context $context): void
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);

        self::ensureJitHelperCompiled($context);
        foreach (self::ABI_TO_HELPER as $abi => $helper) {
            if ('__compiler_fclose' === $abi || '__compiler_pclose' === $abi) {
                self::implementCloseBridge($context, $abi, $helper);
                continue;
            }
            self::implementIfMissing($context, $abi, $helper);
        }
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementCloseBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && !\PHPCompiler\JIT\Builtin\StreamIoRuntime::isDeferStub($probe)) {
            // Rebuild so thin AOT always clears LLVM handle slots after NestedJIT fclose (#30792).
            foreach (\array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
        } elseif (null !== $probe && \PHPCompiler\JIT\Builtin\StreamIoRuntime::isDeferStub($probe)) {
            foreach (\array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock('stream_lifecycle_close_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $handleI32 = $context->builder->trunc($handle, $i32);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$handleI32]
        );
        StreamLibcHandleRuntime::emitClearLlvmHandleSlot($context, $handle);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementIfMissing(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock('stream_lifecycle_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $handleI32 = $context->builder->trunc($fn->getParam(0), $i32);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [$handleI32]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamLifecycleJitHelper compile (#20966)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::LIBC_HELPER_PATH,
            self::LIBC_HELPERS,
            '#20966'
        );
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#20966'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after JitStreamLifecycleKernel bridge (#20966)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
