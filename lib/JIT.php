<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/lib/JIT.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';
require_once __DIR__.'/JIT/RuntimeInitVmContext.php';
require_once __DIR__.'/JIT/RuntimeInitCompiler.php';
require_once __DIR__.'/JIT/M3EmitTuTrivialEchoAot.php';
require_once __DIR__.'/JIT/VmSpineSmokeNative.php';
require_once __DIR__.'/JIT/VmDriverExecuteNative.php';
require_once __DIR__.'/JIT/VmUnitProbeExecuteNative.php';

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\JIT\Builtin\AttributeRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IssetHelper;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\JIT\Variable;

use PHPCompiler\Func as CoreFunc;

use PHPLLVM;

class JIT {

    private static int $functionNumber = 0;
    private static int $blockNumber = 0;

    public int $optimizationLevel = 3;


    private array $stringConstant = [];
    private array $intConstant = [];
    private array $builtIns = [];

    private array $queue = [];

    private ?Block $m3EmitTuMainBlock = null;
    private ?Block $m3CompileDriverMainBlock = null;
    private bool $m3EmitTuRuntimeSpineLowered = false;
    private bool $m3CompileDriverRuntimeSpineLowered = false;
    private ?Block $m3EmitTuTrivialEchoBlock = null;
    private ?string $m3EmitTuTrivialEchoSource = null;
    private bool $m3EmitTuSidecarsCached = false;

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function compile(Block $block): PHPLLVM\Value {
        JIT\Progress::noteFunction('jit_compile_begin');
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isM3EmitTuScriptMain($block)) {
            $this->m3EmitTuMainBlock = $block;
        }
        if ($this->shouldUseM3CompileDriverMainNative() && $this->isM3CompileDriverBundleScriptMain($block)) {
            $this->m3CompileDriverMainBlock = $block;
        }
        JIT\Progress::noteFunction('jit_compile_compile_block_begin');
        $return = $this->compileBlock($block);
        JIT\Progress::noteFunction('jit_compile_compile_block_done');
        JIT\Progress::noteFunction('jit_compile_run_queue_begin');
        $this->runQueue();
        JIT\Progress::noteFunction('jit_compile_run_queue_done');
        JIT\Progress::noteFunction('jit_compile_finalize_m3_emit_tu_spine_begin');
        $this->finalizeM3EmitTuRuntimeSpineAfterQueue();
        JIT\Progress::noteFunction('jit_compile_finalize_m3_emit_tu_spine_done');

        JIT\Progress::noteFunction('jit_compile_done');
        return $return;
    }

    public function compileFunc(CoreFunc $func): void {
        if ($func instanceof CoreFunc\PHP) {
            $name = $func->getName();
            // Large switch crashes LLVM during JIT (issue #540); VM uses host PHP for this helper.
            if ('opcode_type_name' === $name || str_ends_with($name, '\\opcode_type_name')) {
                return;
            }
            $skipName = $this->jitFunctionSkipName($name, $func->block);
            if (
                $this->shouldUseSelfHostJitStubs()
                && JIT\VmUnitProbeExecuteNative::isVmUnitProbeRunName($skipName)
            ) {
                $this->compileVmUnitProbeRunNative(
                    $this->llvmInternalName($name),
                    $func->block,
                    $name
                );

                return;
            }
            if (
                $this->shouldUseSelfHostJitStubs()
                && JIT\VmDriverExecuteNative::isBinVmRunName($skipName, $func->block)
            ) {
                $this->compileBinVmRunNative(
                    $this->llvmInternalName($name),
                    $func->block,
                    $name
                );

                return;
            }
            if (
                $this->isSkippedVmHotPathName($skipName)
                || $this->isSkippedCompilerHotPathName($skipName)
                || $this->isSkippedWebBootstrapHotPathName($skipName)
                || $this->isSkippedLibSpineSmokeHotPathName($skipName)
                || $this->isSkippedSelfHostEntryName($skipName)
                || $this->isSkippedBootstrapInterpreterHotPathName($skipName)
            ) {
                $this->compileBlock($func->block, $name);

                return;
            }
            $this->compileBlock($func->block, $name);
            $this->runQueue();
            return;
        } elseif ($func instanceof CoreFunc\JIT) {
            // No need to do anything, already compiled
            return;
        } elseif ($func instanceof CoreFunc\Internal) {
            $name = strtolower($func->getName());
            if (SelfHostBuiltinPolicy::shouldExternalStub($name)) {
                $this->context->functionProxies[$name] = new JIT\Call\ExternalMethod($func->getName());

                return;
            }
            $this->context->functionProxies[$name] = $func;

            return;
        }
        throw new \LogicException("Unknown func type encountered: " . get_class($func));
    }

    private function runQueue(): void {
        while (!empty($this->queue)) {
            $run = array_shift($this->queue);
            $classId = $this->context->scope->classId;
            $className = $this->context->scope->className;
            $calledClassName = $this->context->scope->calledClassName;
            $this->context->scope = new JIT\Scope();
            $this->context->scope->classId = $classId;
            $this->context->scope->className = $className;
            $this->context->scope->calledClassName = $calledClassName;
            $this->context->scopeStack = [];
            $this->context->inlineIncludeReturnOperands = [];
            $this->context->coalesceAssignTargets = new \SplObjectStorage();
            $this->compileBlockInternal($run[0], $run[1], null, null, ...$run[2]);
        }
    }

    /**
     * php-cfg dead operands before branchIf run before any successor; skip inside inlined
     * includes so template locals survive layout title-branch partial includes (#784, #764).
     */
    private function shouldFreeDeadVariablesBeforeBranch(): bool
    {
        return 0 === $this->context->inlineIncludeDepth;
    }

    /**
     * ?? on superglobals can disturb inherited include locals; restore before use (#866, #784).
     */
    private function maybeRefreshIncludeBindingsBeforeUse(): void
    {
        if ($this->context->inlineIncludeDepth > 0) {
            JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
        }
    }

    /** Self-host AOT sets PHP_COMPILER_SELFHOST_AOT=1 (#816, #557). */
    private function shouldUseSelfHostJitStubs(): bool
    {
        $flag = getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Bundle-only PHP constants (spine smoke defines; bin/compile.php AOT folds false — #2600). */
    /**
     * Fold OpCode::* class constants when php-cfg scopes the class as Type (#2666).
     */
    private function jitFoldOpCodeClassConstant(Operand $classOp, string $constName): ?JIT\Variable
    {
        if (!$classOp instanceof Operand\Literal) {
            return null;
        }
        $ref = OpCode::class.'::'.$constName;
        if (!defined($ref)) {
            return null;
        }
        $lit = new Operand\Literal(constant($ref));
        $lit->type = Type::int();

        return JIT\Variable::fromLiteral($this->context, $lit);
    }

    private function jitFoldPhpCompilerBundleConstant(string $label): ?JIT\Variable
    {
        if (
            'PHP_COMPILER_LIB_SPINE_SMOKE' !== $label
            && !str_ends_with($label, '\\PHP_COMPILER_LIB_SPINE_SMOKE')
        ) {
            return null;
        }
        // Only compiler_lib_spine_smoke/main.php defines this constant; references from
        // bin/compile.php cli_driver must fold false at AOT link (#2600, #2697).
        $lit = new Operand\Literal(false);
        $lit->type = Type::bool();

        return JIT\Variable::fromLiteral($this->context, $lit);
    }

    /**
     * Link-time only: skip non-jittable ext/ class bodies when building native emit helper (#1983).
     * Does not enable self-host Runtime/Compiler stubs (unlike PHP_COMPILER_SELFHOST_AOT).
     */
    private function shouldUseEmitHelperLinkStubs(): bool
    {
        $flag = getenv('PHP_COMPILER_EMIT_HELPER_LINK');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * M5 vendor prelink: AOT-compile literal-require vendor bundles without full class lowering (#1416).
     * Set by script/bootstrap-vendor-objects.php during --compile only.
     */
    private function shouldUseVendorPrelinkJitStubs(): bool
    {
        $flag = getenv('PHP_COMPILER_VENDOR_PRELINK');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    private function shouldSkipExternalClassBodyLowering(int $classId): bool
    {
        if ($this->shouldUseSelfHostJitStubs()
            || $this->shouldUseEmitHelperLinkStubs()
            || $this->shouldUseM3EmitTuNativeBridge()
            || $this->shouldUseVendorPrelinkJitStubs()
            || $this->isBundledSuperglobalsClass($classId)
        ) {
            return true;
        }
        $className = strtolower($this->context->type->object->classNameForId($classId));
        if ('' === $className) {
            return false;
        }

        return str_starts_with($className, 'phpcfg\\')
            || str_starts_with($className, 'phptypes\\')
            || str_starts_with($className, 'phpllvm\\')
            || str_starts_with($className, 'nikic\\');
    }

    /** Opt-in when linking test/selfhost compile_driver.php bundles (#1056, #1768). */
    private function shouldUseM3CompileDriverMainNative(): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        $flag = getenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * Inventory-scale M3 emit via compile_driver.php {main} — no separate *_m3_emit_native_entry.php (#2843).
     */
    private function shouldUseM3InventoryEmitDriver(): bool
    {
        if (!$this->shouldUseM3CompileDriverMainNative()) {
            return false;
        }
        foreach (['PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', 'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER'] as $envKey) {
            $flag = getenv($envKey);
            if ('1' === $flag || 'true' === strtolower((string) $flag)) {
                return true;
            }
        }

        return false;
    }

    private function isM3CompileDriverScriptMain(Block $block): bool
    {
        return null !== $block->func
            && null === $block->func->class
            && '{main}' === $block->func->name;
    }

    /**
     * Host-compile a functional production driver (bin/compile.php) — not link-only sidecar bytes (#1521).
     *
     * Sidecar registration keeps {main} stubbed; set this env when emitting a driver that must run argv/compile.
     */
    private function shouldUseM5DriverHostCompile(): bool
    {
        $flag = getenv('PHP_COMPILER_M5_DRIVER_HOST');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** M5 emit sidecar host-compile targets — stub {main} under self-host AOT (#2697, #2699). */
    private function isM5BootstrapSidecarScriptMain(Block $block): bool
    {
        if ($this->shouldUseM5DriverHostCompile()) {
            return false;
        }
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }
        $path = $block->scriptPath();

        // bin/compile.php needs real {main} for native CLI driver sidecars (#2697).
        return str_ends_with($path, '/bin/vm.php')
            || str_ends_with($path, '/src/cli_driver.php');
    }

    private function isM3CompileDriverBundleScriptMain(Block $block): bool
    {
        if (!$this->isM3CompileDriverScriptMain($block)) {
            return false;
        }

        return str_contains($block->scriptPath(), 'compile_driver.php');
    }

    /** Opt-in when linking test/selfhost/compiler_helloworld_smoke/compile_driver.php (#1056). */
    private function shouldUseM3CompileDriverRealLowering(): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $flag = getenv('PHP_COMPILER_M3_COMPILE_DRIVER');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Emit native entry TU only — not compile_driver bundles that include compile_smoke_m3_emit (#1937). */
    private function shouldUseM3EmitTuNativeBridge(): bool
    {
        $flag = getenv('PHP_COMPILER_M3_EMIT_TU');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Bundled bootstrap-aot smoke FUNCDEF names (BootstrapAot / legacy Lint bundle) (#1515). */
    private function isBootstrapHelloWorldSmokeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\helloworld_compile_smoke')
            || 'helloworld_compile_smoke' === $lower
            || str_ends_with($lower, '\\helloworld_compile_smoke');
    }

    /** M3 native emit bridge entrypoints (Runtime parseAndCompile + standalone — #1983, #2294). */
    private function isBootstrapM3RuntimeEmitBridgeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\compile_smoke_m3_emit')
            || 'compile_smoke_m3_emit' === $lower
            || str_ends_with($lower, '\\compile_smoke_m3_emit')
            || str_ends_with($lower, '\\bootstrapaot\\runtime_compile_smoke_m3_emit')
            || 'runtime_compile_smoke_m3_emit' === $lower
            || str_ends_with($lower, '\\runtime_compile_smoke_m3_emit');
    }

    private function isBootstrapRuntimeCtorSmokeName(string $lower): bool
    {
        return str_ends_with($lower, '\\bootstrapaot\\runtime_ctor_smoke')
            || 'runtime_ctor_smoke' === $lower
            || str_ends_with($lower, '\\runtime_ctor_smoke');
    }

    /** M3 HelloWorld compile driver: real LLVM lowering for parseAndCompile + standalone emit (#1056, #1402). */
    private function isM3CompileDriverRealLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        if ($this->isM3CompileDriverSpineDenyName($lower)) {
            return false;
        }
        if (str_ends_with($lower, '\\runtime::__construct')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::parseandcompile')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjitcontext')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::createjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::jitcontextforloadjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjitcompilemodulefuncs')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::standalone')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::parse')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::parseandcompileemitsmoke')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::compile')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadjit')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initvmcontext')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initparsepipeline')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::initcompiler')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::loadcoremodules')) {
            return true;
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            return true;
        }
        if (str_ends_with($lower, 'slotindexforvariablename')) {
            return true;
        }
        if ($this->shouldUseM5DriverHostCompile()) {
            if ('run' === $lower || str_ends_with($lower, '\\php_compiler_cli_dispatch')
                || str_ends_with($lower, '\\php_compiler_cli_should_run_entry_driver')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * LLVM 9 crashes lowering these during M3 compile-driver link; keep stubbed until fixed (#1402).
     *
     * @return list<string> lowercase name fragments
     */
    private function m3CompileDriverSpineDenyNames(): array
    {
        return [
            // Full emit FUNCDEF LLVM 9 link crash (#1514); inline emit in compile_driver compile mode (#1983).
            '\\bootstrapaot\\helloworld_compile_smoke',
            // compile_smoke_m3_emit real-lowers only in native emit TU (#1983), not compile_driver.
            '\\runtime::__destruct',
        ];
    }

    private function isM3CompileDriverSpineDenyName(string $lower): bool
    {
        foreach ($this->m3CompileDriverSpineDenyNames() as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** Block helpers real-lowered on M3 compile-driver spine (#2848). */
    private function isM3CompileDriverBlockPhpLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\block::slotindexforvariablename');
    }

    /**
     * FUNCDEF/DECLARE_METHOD use short names; self-host skip/M3 gates need scoped names (#1402).
     */
    private function jitFunctionSkipName(?string $name, Block $block): string
    {
        $candidate = strtolower((string) $name);
        if (str_contains($candidate, '::')) {
            return $candidate;
        }
        if (null !== $block->func) {
            return strtolower($block->func->getScopedName());
        }

        return $candidate;
    }

    private function compileBlock(Block $block, ?string $funcName = null): PHPLLVM\Value {
        $logicalName = $funcName;
        if (null !== $logicalName && null !== $block->func) {
            JIT\Progress::noteFunction($block->func->getScopedName());
        }
        $skipName = $this->jitFunctionSkipName($logicalName, $block);
        if (!is_null($funcName)) {
            $internalName = $this->llvmInternalName($funcName);
        } else {
            $internalName = "internal_" . (++self::$functionNumber);
        }
        if (str_contains($internalName, 'opcode_type_name')) {
            return $this->compileSkippedOpcodeNameStub($internalName, $block);
        }
        // M5 bootstrap sidecar: CLI entry scripts under `PHP_COMPILER_SELFHOST_AOT=1` only need a
        // linkable bundle; stub {main} to avoid LLVM 9 crashing while lowering argv driver chains
        // (#2697, #2699). `PHP_COMPILER_M5_DRIVER_HOST=1` opts into real argv lowering (#1521).
        if (
            $this->shouldUseSelfHostJitStubs()
            && !$this->shouldUseM5DriverHostCompile()
            && null === $logicalName
            && null !== $block->func
            && '{main}' === $block->func->name
            && $this->isM5BootstrapSidecarScriptMain($block)
        ) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, '{main}');
        }
        if ($this->shouldUseM3CompileDriverMainNative() && $this->isM3CompileDriverBundleScriptMain($block)) {
            return $this->compileM3CompileDriverMainNative($internalName, $block, $logicalName);
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isM3EmitTuScriptMain($block)) {
            return $this->compileM3EmitTuMainNative($internalName, $block, $logicalName);
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $m3EmitRuntime = strtolower($logicalName);
            if ($this->isM3EmitTuRuntimeSpineLoweringName($m3EmitRuntime)) {
                $methodLc = substr($m3EmitRuntime, (int) strrpos($m3EmitRuntime, '::') + 2);
                if ($this->shouldUseM3EmitTuRuntimeMethodStub($methodLc)) {
                    return $this->compileM3EmitTuRuntimeSpineStub(
                        $internalName,
                        $block,
                        $logicalName,
                        $m3EmitRuntime
                    );
                }
            }
            if ($this->isM3EmitTuCompilerSpineLoweringName($m3EmitRuntime)) {
                if ('phpcompiler\\compiler::compileemitsmoke' === $m3EmitRuntime) {
                    if (!$this->shouldUseM3CompileDriverRealLowering()) {
                        return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
                            $internalName,
                            $logicalName
                        );
                    }
                } elseif (!$this->shouldUseM3CompileDriverRealLowering()) {
                    return $this->compileSkippedCompilerSplitCfgStub(
                        $internalName,
                        $block,
                        $logicalName ?? $internalName
                    );
                }
            }
        }
        if (
            null !== $logicalName
            && $this->shouldUseM3EmitTuNativeBridge()
            && $this->isBootstrapM3RuntimeEmitBridgeName(strtolower($logicalName))
        ) {
            return $this->compileBootstrapCompileSmokeM3EmitNative($internalName, $block, $logicalName);
        }
        $emitTuSpine = $this->tryCompileM3EmitTuRuntimeSpineNative($internalName, $block, $logicalName);
        if (null !== $emitTuSpine) {
            return $emitTuSpine;
        }
        $emitTuCompiler = $this->tryCompileM3EmitTuCompilerSpineNative($internalName, $block, $logicalName);
        if (null !== $emitTuCompiler) {
            return $emitTuCompiler;
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && null !== $logicalName) {
            $m3Spine = strtolower($logicalName);
            if ($this->isM3CompileDriverCompilerNativeLoweringName($m3Spine)) {
                return JIT\CompilerOperandChainNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VariableTypeMapNative::isNativeLoweringName($m3Spine)) {
                return JIT\VariableTypeMapNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\OperandNameNative::isNativeLoweringName($m3Spine)) {
                return JIT\OperandNameNative::compile(
                    $this->context,
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjit')) {
                return $this->compileRuntimeLoadJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjitcontext')) {
                return $this->compileRuntimeLoadJitContextM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::createjit')) {
                return $this->compileRuntimeCreateJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::jitcontextforloadjit')) {
                return $this->compileRuntimeJitContextForLoadJitM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadjitcompilemodulefuncs')) {
                return $this->compileRuntimeLoadJitCompileModuleFuncsM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::__construct')) {
                return $this->compileRuntimeConstructM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initparsepipeline')) {
                return $this->compileRuntimeInitParsePipelineM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initcompiler')) {
                return $this->compileRuntimeInitCompilerM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::initvmcontext')) {
                return $this->compileRuntimeInitVmContextM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::loadcoremodules')) {
                return $this->compileRuntimeLoadCoreModulesM3Native($internalName, $block, $logicalName);
            }
            if (
                $this->shouldUseM3EmitTuNativeBridge()
                && (
                    str_ends_with($m3Spine, '\\runtime::parseandcompile')
                    || str_ends_with($m3Spine, '\\runtime::parseandcompileemitsmoke')
                )
            ) {
                return $this->compileRuntimeParseAndCompileM3Native($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::parse')) {
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('parse')) {
                    return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::compileemitsmoke')) {
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('compileemitsmoke')) {
                    return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::standalone')) {
                if ($this->shouldUseM3EmitTuRuntimeMethodStub('standalone')) {
                    return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
                }

                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if (str_ends_with($m3Spine, '\\runtime::compile')
                || str_ends_with($m3Spine, '\\runtime::parseandcompile')
                || str_ends_with($m3Spine, '\\runtime::parseandcompileemitsmoke')
            ) {
                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->isM3EmitHelperCompilerPhpLoweringName($m3Spine)) {
                return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
            }
            if ($this->isM3CompileDriverBlockPhpLoweringName($m3Spine)) {
                return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
            }
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $m3Compiler = strtolower($logicalName);
            if ('phpcompiler\\compiler::compileemitsmoke' === $m3Compiler
                && !$this->shouldUseM3CompileDriverRealLowering()
            ) {
                return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction($internalName, $logicalName);
            }
        }
        if ($this->shouldUseSelfHostJitStubs() && null !== $logicalName) {
            $vmSpineLc = strtolower($logicalName);
            if (JIT\VmSpineSmokeNative::isVmRunSmokeName($vmSpineLc)) {
                return $this->compileVmRunSmokeNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VmUnitProbeExecuteNative::isVmUnitProbeRunName($vmSpineLc)) {
                return $this->compileVmUnitProbeRunNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
            if (JIT\VmDriverExecuteNative::isBinVmRunName($vmSpineLc, $block)) {
                return $this->compileBinVmRunNative(
                    $this->llvmInternalName($internalName),
                    $block,
                    $logicalName
                );
            }
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && $this->isSuperglobalNameJitFunction($logicalName)
        ) {
            return $this->compileSuperglobalNameNative($internalName, $block, $logicalName);
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && JIT\OperandNameNative::isNativeLoweringName(strtolower($logicalName))
        ) {
            return JIT\OperandNameNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isSkippedVmHotPathName($skipName)) {
            return $this->compileSkippedVmHotPathStub($internalName, $block, $logicalName ?? $internalName);
        }
        if ($this->isSkippedM3EmitTuBundledHelperName($skipName)) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }
        if ($this->isSkippedCompilerHotPathName($skipName)
            || $this->isSkippedWebBootstrapHotPathName($skipName)
            || $this->isSkippedLibSpineSmokeHotPathName($skipName)
            || $this->isSkippedSelfHostEntryName($skipName)
            || $this->isSkippedBootstrapInterpreterHotPathName($skipName)
        ) {
            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }
        if (
            $this->shouldUseSelfHostJitStubs()
            && null !== $logicalName
            && str_ends_with(strtolower($logicalName), '\\runtime::__construct')
            && !$this->shouldUseM3CompileDriverRealLowering()
        ) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        // Emit TU: stub bundled lib/ except M3 compile-driver Compiler/Web CFG (#2540, #2633).
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $logicalName) {
            $emitLc = strtolower($logicalName);
            if ($this->shouldUseM3CompileDriverRealLowering()
                && (
                    $this->isM3EmitTuCompilerCompileChainLoweringName($emitLc)
                    || $this->isLiteralIncludeDiscoveryRealLoweringMethod($emitLc)
                    || $this->isDeployRootRealLoweringMethod($emitLc)
                    || $this->isSourceBundlerRealLoweringMethod($emitLc)
                    || $this->isConstStringFolderRealLoweringMethod($emitLc)
                    || $this->isSuperglobalsRealLoweringMethod($emitLc)
                    || $this->isM3EmitTuRuntimeCompileDriverSpineLoweringName($emitLc)
                    || $this->isM3CompileDriverBlockPhpLoweringName($emitLc)
                )
            ) {
                return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
            }

            return $this->compileSkippedCompilerSplitCfgStub($internalName, $block, $logicalName ?? $internalName);
        }

        return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $funcName);
    }

    private function compileBlockPhpLowering(
        string $internalName,
        Block $block,
        ?string $logicalName,
        ?string $funcName
    ): PHPLLVM\Value {
        $args = [];
        $rawTypes = [];
        $argVars = [];
        if (!is_null($block->func)) {
            $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__value__';
            if ('__construct' === strtolower($block->func->name)) {
                $callbackType = 'void';
            }
            $returnType = $this->context->getTypeFromString($callbackType);
            $this->context->functionReturnType[strtolower($logicalName ?? $internalName)] = $callbackType;

            if ($this->instanceMethodUsesThis($block)) {
                $rawTypes[] = Type::object();
                $args[] = $this->context->getTypeFromString('__object__*');
            }
            $callbackType .= '(*)(';
            $callbackSep = '';
            foreach ($args as $type) {
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
            }
            foreach ($block->func->params as $idx => $param) {
                $rawType = $this->rawTypeFromCfgParam($param);
                $type = $this->llvmTypeForCfgParam($param);
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
                $rawTypes[] = $rawType;
                $args[] = $type;
            }
            if ($this->shouldUseSelfHostJitStubs() && null !== $logicalName) {
                $args = $this->normalizeSelfHostNativeCallArgTypes($args, $logicalName);
            }
            $callbackType .= ')';
        } else {
            $callbackType = 'void(*)()';
            $returnType = $this->context->getTypeFromString('void');
        }

        $isVarArgs = false;

        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType(
                $returnType,
                $isVarArgs,
                ...$args
            )
        );

        foreach ($args as $idx => $arg) {
            $argVars[] = new Variable($this->context, Variable::getTypeFromType($rawTypes[$idx]), Variable::KIND_VALUE, $func->getParam($idx));
        }

        $lcname = strtolower($logicalName ?? $internalName);
        $this->context->functions[$lcname] = $func;
        if (!is_null($funcName)) {
            $lcname = strtolower($funcName);
            $this->context->activeFunction = $lcname;
            $this->context->functions[$lcname] = $func;
            if ($isVarArgs) {
                $this->context->functionProxies[$lcname] = new JIT\Call\Vararg($func, $funcName, count($args));
            } else {
                $defaultArgs = $this->collectParamDefaults($block);
                $variadicArgIndex = null;
                if (null !== $block->variadicParamIndex) {
                    $variadicArgIndex = $block->variadicParamIndex;
                    if ($this->instanceMethodUsesThis($block)) {
                        ++$variadicArgIndex;
                    }
                }
                $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                    $func,
                    $funcName,
                    $args,
                    $defaultArgs,
                    $variadicArgIndex,
                    $this->paramTypeConstraintsForNativeCall($block)
                );
            }
        }

        $this->queue[] = [$func, $block, $argVars];
        if ($callbackType === 'void(*)()') {
            $this->context->addExport($internalName, $callbackType, $block);
        }
        return $func;
    }

  /** LLVM/C symbols reserved for the AOT entry wrapper and runtime init (#2779). */
    private const LLVM_RESERVED_FUNCTION_NAMES = [
        'main' => true,
        '__init__' => true,
        '__shutdown__' => true,
    ];

    private function llvmInternalName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if (isset(self::LLVM_RESERVED_FUNCTION_NAMES[$sanitized])) {
            return 'php_user_'.$sanitized;
        }

        return $sanitized;
    }

    private function isSuperglobalNameJitFunction(string $name): bool
    {
        $lower = strtolower($name);

        return str_ends_with($lower, '::issuperglobalname') || 'issuperglobalname' === $lower;
    }

    /** Native vm_run_smoke for M2 lib spine VM -r gate (#1846). */
    private function compileVmRunSmokeNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param);
            }
        }

        return JIT\VmSpineSmokeNative::compileVmRunSmokeNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native vm_unit_probe_run for M3 VM unit probe execute gate (#2619). */
    private function compileVmUnitProbeRunNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param);
            }
        }

        return JIT\VmUnitProbeExecuteNative::compileVmUnitProbeRunNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native bin/vm.php run() for M2 VM driver execute gate (#2201). */
    private function compileBinVmRunNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $paramTypes = [];
        if (null !== $block->func) {
            foreach ($block->func->params as $param) {
                $paramTypes[] = $this->llvmTypeForCfgParam($param);
            }
        }

        return JIT\VmDriverExecuteNative::compileBinVmRunNative(
            $this->context,
            $internalName,
            $logicalName,
            $paramTypes
        );
    }

    /** Native __compiler_is_superglobal_name for self-host AOT (issue #1056). */
    private function compileSuperglobalNameNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $strPtr = $this->context->getTypeFromString('__string__*');
        $boolTy = $this->context->getTypeFromString('bool');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($boolTy, false, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $boxed = \PHPCompiler\ext\standard\JitSuperglobalName::invoke(
            $this->context,
            $func->getParam(0)
        );
        $long = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $boxed
        );
        $this->context->builder->returnValue(
            $this->context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $long,
                $long->typeOf()->constInt(0, false)
            )
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'bool';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /**
     * M3 compile-driver loadJit (#1402, #2847): outer orchestration; inner helpers are separate FUNCDEFs.
     * Calls loadJitContext via jitContextForLoadJit — keep loadJitContext as its own LLVM function (#2846).
     */
    private function compileRuntimeLoadJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * M3 compile-driver loadJitContext (#1402, #2846): separate FUNCDEF from loadJit to avoid LLVM 9 inlining crash.
     */
    private function compileRuntimeLoadJitContextM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver createJit (#1402, #2847): separate FUNCDEF from loadJit (LLVM 9 inlining). */
    private function compileRuntimeCreateJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver jitContextForLoadJit (#1402, #2847): thin wrapper; own FUNCDEF from loadJit. */
    private function compileRuntimeJitContextForLoadJitM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver loadJitCompileModuleFuncs (#1402, #2847): module foreach; separate FUNCDEF. */
    private function compileRuntimeLoadJitCompileModuleFuncsM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** M3 compile-driver Runtime::__construct (#1494): C-floor vmContext — not full PHP CFG (LLVM 9; #2600). */
    private function compileRuntimeConstructM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3CompileDriverRealLowering()
            || $this->shouldUseM3EmitTuRuntimeMethodStub('__construct')
        ) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /**
     * Emit-helper link without full compile_driver: real-lower Runtime parse spine (#2559).
     *
     * Uses host parse of lib/Runtime.php at link time; avoids LLVM 9 global ctor from bundling PHPTypes in emit TU.
     */
    private function shouldUseM3EmitTuEmitHelperSpineRealLowering(): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge() || !$this->shouldUseEmitHelperLinkStubs()) {
            return false;
        }
        if ($this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        $flag = getenv('PHP_COMPILER_M3_EMIT_HELPER_SPINE');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Emit TU null-returning stubs unless M3 real-lowering is enabled (#2512, #2542). */
    private function shouldUseM3EmitTuRuntimeMethodStub(string $methodLc): bool
    {
        if ($this->shouldUseM3InventoryEmitDriver()) {
            static $inventoryEmitSpine = [
                '__construct',
                'initparsepipeline',
                'initcompiler',
                'initvmcontext',
                'loadcoremodules',
                'parse',
                'compileemitsmoke',
                'standalone',
            ];
            if (in_array($methodLc, $inventoryEmitSpine, true)) {
                return true;
            }
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return true;
        }

        return !$this->isM3CompileDriverRealLoweringName('phpcompiler\\runtime::'.$methodLc);
    }

    /**
     * Native parseAndCompile for M3 emit TU — avoids LLVM 9 crash lowering full Runtime::compile (#2516).
     */
    private function compileRuntimeParseAndCompileM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
        }
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }

        return \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::declareRuntimeParseAndCompileNative(
            $this->context,
            $this->llvmInternalName($internalName),
            $logicalName
        );
    }

    /**
     * Native Runtime::__construct for emit TU — C-floor vmContext when real-lowering (#2513, #2550).
     */
    private function emitM3EmitTuRuntimeConstructNativeFunction(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $i64 = $this->context->getTypeFromString('int64');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($voidTy, false, $objectPtr, $i64)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        if ($this->shouldUseM3CompileDriverRealLowering() || $this->shouldUseSelfHostJitStubs()) {
            \PHPCompiler\JIT\RuntimeInitVmContext::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $modeSlot = $this->context->type->object->propertyFetch(
                $func->getParam(0),
                'PHPCompiler\\Runtime',
                'mode'
            );
            $modeVar = new JIT\Variable(
                $this->context,
                JIT\Variable::TYPE_NATIVE_LONG,
                JIT\Variable::KIND_VALUE,
                $func->getParam(1)
            );
            $this->context->type->object->propertyStore(
                $modeSlot->objectPropertySlot,
                $modeVar,
                JIT\Variable::TYPE_NATIVE_LONG
            );
        }
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $i64],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    private function compileRuntimeInitParsePipelineM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('initparsepipeline')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    private function compileRuntimeInitCompilerM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('initcompiler')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if ($this->shouldUseM3EmitTuNativeBridge()) {
            $lcname = strtolower($logicalName);
            if (isset($this->context->functions[$lcname])) {
                return $this->context->functions[$lcname];
            }
            $objectPtr = $this->context->getTypeFromString('__object__*');
            $func = $this->context->module->addFunction(
                $this->llvmInternalName($internalName),
                $this->context->context->functionType(
                    $this->context->getTypeFromString('void'),
                    false,
                    $objectPtr
                )
            );
            $bb = $func->appendBasicBlock('entry');
            $saved = $this->context->builder;
            $this->context->builder = $this->context->context->builderCreate();
            $this->context->builder->positionAtEnd($bb);
            \PHPCompiler\JIT\RuntimeInitCompiler::emit(
                $this->context,
                $this->context->type->object,
                $func->getParam(0)
            );
            $this->context->builder->returnVoid();
            $this->context->builder->clearInsertionPosition();
            $this->context->builder = $saved;
            $this->context->functions[$lcname] = $func;
            $this->context->functionReturnType[$lcname] = 'void';
            $this->context->functionProxies[$lcname] = new JIT\Call\Native(
                $func,
                $logicalName,
                [$objectPtr],
                $this->collectParamDefaults($block)
            );

            return $func;
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    private function compileRuntimeInitVmContextM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        // Emit TU and compile_driver share C-floor initVmContext (#2513, #2540).
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType(
                $this->context->getTypeFromString('void'),
                false,
                $objectPtr
            )
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        \PHPCompiler\JIT\RuntimeInitVmContext::emit($this->context, $this->context->type->object, $func->getParam(0));
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            $this->collectParamDefaults($block)
        );
        return $func;
    }

    private function compileRuntimeLoadCoreModulesM3Native(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        if ($this->shouldUseM3EmitTuRuntimeMethodStub('loadcoremodules')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }

        return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
    }

    /** No-op init helper for emit TU link — real init deferred to Batch A (#2516). */
    private function emitM3EmitTuRuntimeInitVoidStub(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($voidTy, false, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    private function compileRuntimeSpinePhpLowering(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        return $this->compileBlockPhpLowering($internalName, $block, $logicalName, $logicalName);
    }

    /**
     * Stub out opcode_type_name() — the real implementation is a large switch that crashes LLVM 9 JIT (#540).
     */
    private function compileSkippedOpcodeNameStub(string $internalName, Block $block): PHPLLVM\Value
    {
        $lcname = strtolower($internalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $mangled = $this->llvmInternalName($internalName);
        $func = $this->context->module->addFunction(
            $mangled,
            $this->context->context->functionType(
                $this->context->getTypeFromString('__string__*'),
                false,
                $this->context->getTypeFromString('int64')
            )
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue(
            $this->context->builder->load($this->context->constantStringFromString('TYPE_UNKNOWN'))
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;

        return $func;
    }

    private function isSkippedVmHotPathName(string $name): bool
    {
        $lower = strtolower($name);
        // Self-host AOT bundles lib/VM.php for closure lint only; stub the interpreter (#816, #913).
        if (str_contains($lower, '\\vm::')) {
            return true;
        }

        return str_ends_with($lower, '::runframes') || str_ends_with($lower, '::defineclass')
            || str_ends_with($lower, '::getframe');
    }

    /**
     * M3 emit TU bundles Compiler/Runtime for link only — stub JIT/VM/Lint bodies (#2442).
     */
    private function isSkippedM3EmitTuBundledHelperName(string $name): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        $lower = strtolower($name);
        if ($this->isM3EmitTuRuntimeSpineLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitTuCompilerSpineLoweringName($lower)) {
            return false;
        }
        if ($this->isBootstrapM3RuntimeEmitBridgeName($lower)) {
            return false;
        }

        return str_contains($lower, '\\jit\\')
            || str_contains($lower, '\\lint\\')
            || str_contains($lower, '\\vm\\')
            || str_contains($lower, '\\printer::')
            || str_contains($lower, '\\handler::')
            || str_contains($lower, '\\optimizer::');
    }

    /** Stub bundled lib/ interpreter helpers for self-host AOT (#557, #816). */
    private function isSkippedBootstrapInterpreterHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitTuRuntimeSpineLoweringName($lower)) {
            return false;
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && JIT\VariableTypeMapNative::isNativeLoweringName($lower)) {
            return false;
        }
        if ($this->isSkippedSelfHostEntryName($name)) {
            return false;
        }
        if (str_contains($lower, '\\vm::')
            || str_contains($lower, '\\block::')
            || str_contains($lower, '\\frame::')
            || str_contains($lower, '\\module::')
            || str_contains($lower, '\\runtime::')
            || $this->isSkippedJitResultHotPathName($lower)
        ) {
            return true;
        }
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }

        return str_contains($lower, '\\vm\\')
            || str_contains($lower, '\\vm\\variable::')
            || str_contains($lower, '\\printer::')
            || str_contains($lower, '\\opcode::')
            || str_contains($lower, '\\methodvisibility::')
            || str_contains($lower, '\\nullsafelivenessdetector::')
            || str_contains($lower, '\\moduleabstract::')
            || str_contains($lower, '\\opcodenames::')
            || str_contains($lower, '\\lint\\')
            || (str_contains($lower, '\\bootstrapaot\\') && !$this->isM3CompileDriverRealLoweringName($lower))
            || str_contains($lower, '\\jit\\')
            || str_contains($lower, '\\func\\jit::')
            || str_contains($lower, '\\func\\internal::')
            || str_contains($lower, '\\jit::');
    }

    /** Skip JIT\\Result FFI bodies (getCallable/getFunc) during self-host native link (#816). */
    private function isSkippedJitResultHotPathName(string $lowerName): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        if ($this->isM3CompileDriverRealLoweringName($lowerName)) {
            return false;
        }

        return str_contains($lowerName, '\\jit\\result::');
    }

    /** M3 emit TU: PHP CFG lowering for compile spine only (#1937, #1983). */
    private function isM3EmitHelperCompilerPhpLoweringName(string $lower): bool
    {
        if (!$this->shouldUseEmitHelperLinkStubs()) {
            return false;
        }
        // Emit TU links via native bridge + LLVM stubs; PHP CFG here segfaults LLVM 9 (#2540).
        if ($this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        if ($this->isM3EmitTuCompilerSpineLoweringName($lower)) {
            return true;
        }

        return str_ends_with($lower, '\\compiler::compile')
            || str_ends_with($lower, '\\compiler::compilefunc');
    }

    /**
     * Minimal Compiler CFG chain for native emit TU (trivial echo sources — #1937).
     *
     * @return list<string> method suffixes after \\compiler::
     */
    private function m3EmitTuCompilerSpineMethodSuffixes(): array
    {
        return [
            'compile',
            'compileemitsmoke',
            'compilefunc',
            'compilecfgblock',
            'compilecfgbranch',
            'compileblock',
            'compileops',
            'compileop',
            'compileparam',
            'compileterminal',
            'compileoperand',
            'compilestmt',
            'compileexpr',
            'compileboolconstant',
            'compilebooltemporary',
        ];
    }

    /**
     * Compiler helpers for native lowering on M3 compile_driver link (#1768).
     *
     * PHP CFG lowering of these hits LLVM 9 dominance verify failures; use
     * {@see CompilerOperandChainNative} instead.
     *
     * @return list<string> method suffixes after \\compiler::
     */
    private function m3CompileDriverCompilerNativeLoweringSuffixes(): array
    {
        return [
            'operandschainequal',
            'unwrapoperandchain',
        ];
    }

    /**
     * @return list<string> method suffixes after \\compiler::
     */
    private function m3CompileDriverCompilerPhpLoweringSuffixes(): array
    {
        // M5 (#2666): allow the M3 emit helper to compile inventory-scale sources (lib/Compiler.php,
        // bin/compile.php) by lowering a minimal Compiler compile chain (avoid LLVM 9 emit-TU link
        // crashes when lowering the full Compiler into the helper module; #2540).
        return [
            'compile',
            'compilecfgblock',
            'compilecfgbranch',
            'compileblock',
            'compileops',
            'compileop',
            'compilestmt',
            'compileexpr',
            'compileoperand',
            'compileterminal',
            'compileparam',
            'compilefunction',
            'compilefunccall',
            // class-heavy sources (lib/*.php) need class lowering
            'compileclasslike',
            'compileclassbody',
        ];
    }

    private function isM3CompileDriverCompilerNativeLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ($this->m3CompileDriverCompilerNativeLoweringSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3CompileDriverCompilerPhpLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ($this->m3CompileDriverCompilerPhpLoweringSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3EmitTuCompilerSpineLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        foreach ($this->m3EmitTuCompilerSpineMethodSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compiler CFG helpers allowed through emit-TU stub gate for M3 compile-driver (#2633).
     *
     * Kept smaller than {@see m3EmitTuCompilerSpineMethodSuffixes()} to avoid LLVM 9 link crash
     * when lowering the full Compiler into the emit-helper module (#2540).
     */
    private function isM3EmitTuCompilerCompileChainLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ($this->m3EmitTuCompilerCompileChainLoweringSuffixes() as $suffix) {
            if (str_ends_with($lower, '\\compiler::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3EmitTuRuntimeCompileDriverSpineLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ([
            'parse',
            'compileemitsmoke',
            'initparsepipeline',
            'initcompiler',
            'loadcoremodules',
        ] as $suffix) {
            if (str_ends_with($lower, '\\runtime::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function m3EmitTuCompilerCompileChainLoweringSuffixes(): array
    {
        return [
            'compilecfgblock',
            'compilecfgbranch',
            'compileblock',
            'compileops',
            'compileop',
            'compilestmt',
            'compileexpr',
            'compileoperand',
            'compileterminal',
            'compileparam',
            'compilefunction',
            'compilefunccall',
            'compileboolconstant',
            'compilebooltemporary',
            'compilecoalesce',
            'compilenullsafe',
            'compileisset',
            'compileissetmulti',
            'compilearrayliteral',
            'compilearraydimfetchread',
            'compileincludeop',
            'compileclasslike',
            'compileclassbody',
            'compileglobalconst',
            'compileclassconstfetch',
            'compileinstanceof',
            'compileswitchasjumpifchain',
            'getopcodetype',
            'compiletypeconstrainedvariable',
            'trycompiledefineasglobalconst',
            'tryfoldvariablefunctionname',
            'compilecallargsends',
            'callargunpack',
            'markcallerlocalsusedbyliteralinclude',
            'requireoperandslot',
            'resolvesimplevariablename',
            'operandschainequal',
            'unwrapoperandchain',
            'splitcfgblockafterstringkeyedarray',
            'inheritfuncfromparent',
            'needscfg',
            'unwrap',
            'isarraydim',
            'findcoalesce',
            'resolvecoalesce',
            'resolveisset',
            'isredundantcoalescetailassign',
            'compilefirstclasscallable',
            'compilefirstclassfunctionnameslot',
            'compilefirstclassstaticnameslot',
        ];
    }

    /**
     * Lightweight native stubs for Runtime spine in M3 emit TU — never full PHP CFG (#2442).
     *
     * LLVM 9 crashes lowering initVmContext / parseAndCompile bodies in the emit-helper bundle.
     */
    private function tryCompileM3EmitTuRuntimeSpineNative(
        string $internalName,
        Block $block,
        ?string $logicalName
    ): ?PHPLLVM\Value {
        if (!$this->shouldUseM3EmitTuNativeBridge() || null === $logicalName) {
            return null;
        }
        $emitLc = strtolower($logicalName);
        if (!$this->isM3EmitTuRuntimeSpineLoweringName($emitLc)) {
            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::__construct')) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        if (str_ends_with($emitLc, '\\runtime::initvmcontext')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('initvmcontext')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeInitVmContextM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::initparsepipeline')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('initparsepipeline')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeInitParsePipelineM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::initcompiler')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('initcompiler')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeInitCompilerM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::loadcoremodules')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('loadcoremodules')) {
                return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
            }

            return $this->compileRuntimeLoadCoreModulesM3Native($internalName, $block, $logicalName);
        }
        if (str_ends_with($emitLc, '\\runtime::loadjitcontext')
            || str_ends_with($emitLc, '\\runtime::createjit')
            || str_ends_with($emitLc, '\\runtime::jitcontextforloadjit')
            || str_ends_with($emitLc, '\\runtime::loadjitcompilemodulefuncs')
        ) {
            if ($this->shouldUseM3CompileDriverRealLowering()) {
                return null;
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($emitLc, '\\runtime::loadjit')
            || str_ends_with($emitLc, '\\runtime::jitemitinplace')
        ) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if ($this->shouldUseM3CompileDriverRealLowering()) {
            if (str_ends_with($emitLc, '\\runtime::parse')
                || str_ends_with($emitLc, '\\runtime::compileemitsmoke')
                || str_ends_with($emitLc, '\\runtime::standalone')
                || str_ends_with($emitLc, '\\runtime::compile')
                || str_ends_with($emitLc, '\\runtime::parseandcompile')
                || str_ends_with($emitLc, '\\runtime::parseandcompileemitsmoke')
            ) {
                return null;
            }
        }
        if (str_ends_with($emitLc, '\\runtime::parse')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('parse')) {
                return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
            }

            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::compileemitsmoke')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('compileemitsmoke')) {
                return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
            }

            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::standalone')) {
            if ($this->shouldUseM3EmitTuRuntimeMethodStub('standalone')) {
                return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
            }

            return null;
        }
        if (str_ends_with($emitLc, '\\runtime::compile')
            || str_ends_with($emitLc, '\\runtime::parseandcompile')
            || str_ends_with($emitLc, '\\runtime::parseandcompileemitsmoke')
            || str_ends_with($emitLc, '\\runtime::jitcompileblock')
        ) {
            return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
        }

        return null;
    }

    /** Stub Compiler CFG spine in M3 emit TU — LLVM 9 cannot lower full compile() chain (#2442). */
    private function tryCompileM3EmitTuCompilerSpineNative(
        string $internalName,
        Block $block,
        ?string $logicalName
    ): ?PHPLLVM\Value {
        if (!$this->shouldUseM3EmitTuNativeBridge() || null === $logicalName) {
            return null;
        }
        $emitLc = strtolower($logicalName);
        if (!$this->isM3EmitTuCompilerSpineLoweringName($emitLc)) {
            return null;
        }
        if ('phpcompiler\\compiler::compileemitsmoke' === $emitLc) {
            if (!$this->shouldUseM3CompileDriverRealLowering()) {
                return $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction($internalName, $logicalName);
            }

            return null;
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($emitLc)) {
            return JIT\CompilerOperandChainNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($emitLc)) {
            return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
        }

        return $this->compileSkippedCompilerSplitCfgStub(
            $internalName,
            $block,
            $logicalName
        );
    }

    /** Runtime methods the M3 emit native bridge calls — never self-host stub (#2442). */
    private function isM3EmitTuRuntimeSpineLoweringName(string $lower): bool
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return false;
        }
        foreach ([
            '__construct',
            'initparsepipeline',
            'initcompiler',
            'initvmcontext',
            'loadcoremodules',
            'parse',
            'compile',
            'compileemitsmoke',
            'parseandcompile',
            'parseandcompileemitsmoke',
            'parseandcompilefile',
            'standalone',
            'loadjit',
            'jitcompileblock',
            'jitemitinplace',
        ] as $suffix) {
            if (str_ends_with($lower, '\\runtime::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isM3EmitTuScriptMain(Block $block): bool
    {
        return null !== $block->func
            && null === $block->func->class
            && '{main}' === $block->func->name;
    }

    private function isSkippedCompilerHotPathName(string $name): bool
    {
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitHelperCompilerPhpLoweringName($lower)) {
            return false;
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($lower)) {
            return false;
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($lower)) {
            return false;
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && str_contains($lower, '\\compiler::compileemitsmoke')) {
            return false;
        }
        if ($this->shouldUseSelfHostJitStubs() && str_contains($lower, '\\compiler::')) {
            return true;
        }

        return str_contains($lower, 'splitcfgblockafterstringkeyedarray')
            || str_contains($lower, 'compilecfgbranch')
            || str_contains($lower, 'compilecfgblock')
            || str_contains($lower, 'compileblock')
            || str_contains($lower, 'compileops')
            || str_contains($lower, 'compileclasslike')
            || str_contains($lower, 'compileclassbody')
            || str_contains($lower, 'compilefunction')
            || str_contains($lower, 'compileglobalconst')
            || str_contains($lower, 'compilestmt')
            || str_contains($lower, 'compileop')
            || str_contains($lower, 'compileswitchasjumpifchain')
            || str_contains($lower, 'compileexpr')
            || str_contains($lower, 'getopcodetype')
            || str_contains($lower, 'compileissetmulti')
            || str_contains($lower, 'compileisset')
            || str_contains($lower, 'compilecoalesce')
            || str_contains($lower, 'compilenullsafe')
            || str_contains($lower, 'compileincludeop')
            || str_contains($lower, 'compileparam')
            || str_contains($lower, 'compileterminal')
            || str_contains($lower, 'compilefunccall')
            || str_contains($lower, 'tryfoldvariablefunctionname')
            || str_contains($lower, 'compilecallargsends')
            || str_contains($lower, 'callargunpack')
            || str_contains($lower, 'compilearrayliteral')
            || str_contains($lower, 'compilearraydimfetchread')
            || str_contains($lower, 'compilebooltemporary')
            || str_contains($lower, 'compileboolconstant')
            || str_contains($lower, 'compiletypeconstrainedvariable')
            || str_contains($lower, 'compileclassconstfetch')
            || str_contains($lower, 'compilefirstclasscallable')
            || str_contains($lower, 'compilefirstclassfunctionnameslot')
            || str_contains($lower, 'compilefirstclassstaticnameslot')
            || str_contains($lower, 'compileinstanceof')
            || str_contains($lower, 'trycompiledefineasglobalconst')
            || str_contains($lower, 'markcallerlocalsusedbyliteralinclude')
            || str_contains($lower, 'requireoperandslot')
            || str_contains($lower, 'resolvesimplevariablename')
            || str_contains($lower, 'unwrap')
            || str_contains($lower, 'needscfg')
            || str_contains($lower, 'inheritfuncfromparent')
            || str_contains($lower, 'isarraydim')
            || str_contains($lower, 'findcoalesce')
            || str_contains($lower, 'resolvecoalesce')
            || str_contains($lower, 'resolveisset')
            || str_contains($lower, 'isredundantcoalescetailassign');
    }

    private function isSkippedSelfHostEntryName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        if ($this->isM3CompileDriverRealLoweringName($lower)) {
            return false;
        }
        if ($this->isM3EmitTuRuntimeSpineLoweringName($lower)) {
            return false;
        }
        // M3 compile-smoke wrapper: native bridge in emit TU only (#1983 approach 3, #1937).
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isBootstrapM3RuntimeEmitBridgeName($lower)) {
            return true;
        }
        // Self-host bundle includes Runtime/VM/Func for closure only; stub non-Compiler bodies (#913).
        if (str_contains($lower, '\\runtime::')
            || str_contains($lower, '\\func\\php::')
            || str_contains($lower, '\\func::')
            || str_contains($lower, '\\frame::')
            || str_contains($lower, '\\block::')
        ) {
            return true;
        }

        return str_ends_with($lower, '\\compiler::compilefunc')
            || str_ends_with($lower, '\\compiler::compile')
            || 'type_pair' === $lower
            || $this->isBootstrapRuntimeCtorSmokeName($lower)
            || ($this->isBootstrapHelloWorldSmokeName($lower) && !$this->shouldUseM3CompileDriverRealLowering())
            || ($this->isBootstrapM3RuntimeEmitBridgeName($lower) && !$this->shouldUseM3CompileDriverRealLowering());
    }

    private function isSkippedWebBootstrapHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);
        return (str_contains($lower, '\\web\\includepathresolver::') && !$this->isIncludePathResolverRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\literalincludediscovery::') && !$this->isLiteralIncludeDiscoveryRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\deployroot::') && !$this->isDeployRootRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\sourcebundler::') && !$this->isSourceBundlerRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\conststringfolder::') && !$this->isConstStringFolderRealLoweringMethod($lower))
            || (str_contains($lower, '\\web\\superglobals::')
                && !$this->isSuperglobalsRealLoweringMethod($lower)
                && !str_ends_with($lower, '::issuperglobalname'));
    }


    /** Stub M2 lib spine smoke units (Doctor, Cli, Web drivers, ext/standard JIT leaves) for self-host AOT (#1056). */
    private function isSkippedLibSpineSmokeHotPathName(string $name): bool
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return false;
        }
        $lower = strtolower($name);

        return str_contains($lower, '\\doctor::')
            || str_contains($lower, '\\cli\\')
            || str_contains($lower, '\\web\\cgiaotdriver::')
            || str_contains($lower, '\\web\\cgidriver::')
            || str_contains($lower, '\\web\\projectdeploy::')
            || str_contains($lower, '\\web\\manifestvalidator::')
            || str_contains($lower, '\\web\\projectmanifest::')
            || str_contains($lower, '\\web\\projectautoload::')
            || str_contains($lower, '\\web\\projectbootstrap::')
            || str_contains($lower, '\\web\\responsecontext::')
            || str_contains($lower, '\\web\\devserver::')
            || str_contains($lower, '\\web\\params::')
            || str_contains($lower, '\\aot\\')
            || str_contains($lower, '\\ext\\standard\\')
            || str_contains($lower, '\\ext\\types\\')
            || str_contains($lower, '\\jit\\varfetchhelper::')
            || str_contains($lower, '\\jit\\unsethelper::')
            || str_contains($lower, '\\jit\\arraybuiltinhelper::')
            || str_contains($lower, '\\jit\\reflectionbuiltinhelper::')
            || str_contains($lower, '\\jit\\typecheck::')
            || str_contains($lower, '\\jit\\errorhandlercallbackpolicy::')
            || str_contains($lower, '\\jit\\builtin\\stringparsestr::')
            || str_contains($lower, '\\builtinparamnames::')
            || str_contains($lower, '\\jit\\builtin\\type\\object_::')
            || str_contains($lower, '\\jit\\builtin\\type\\hashtable::')
            || ($this->shouldUseEmitHelperLinkStubs() && str_contains($lower, '\\phptypes\\'));
    }

    /** IncludePathResolver methods with safe LLVM 9 lowering during self-host AOT (#816). */
    private function isIncludePathResolverRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\includepathresolver::resolve');
    }

    /**
     * LiteralIncludeDiscovery real LLVM lowering during M3 compile-driver link (#816, #2843).
     *
     * Entry points call private CFG walkers and ConstStringFolder::foldForInclude; stubbed callees
     * return empty paths and break include bundling in bin/compile.php bundles.
     */
    private function isLiteralIncludeDiscoveryRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ([
            'discoverdirectabsolutepaths',
            'discoverabsolutepaths',
            'pathsfrommainscopeforbundle',
            'pathsfromscript',
            'walkcfgblock',
            'walkcfgblockforbundle',
            'walkcfgblockinternal',
            'isbundlescopeboundary',
        ] as $suffix) {
            if (str_ends_with($lower, '\\web\\literalincludediscovery::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** DeployRoot helpers needed by bin/compile.php include bundling (#1521). */
    private function isDeployRootRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\deployroot::findprojectrootforpath')
            || str_ends_with($lower, '\\web\\deployroot::relativedirfromproject');
    }

    /** SourceBundler entry used when literal includes are folded into one TU (#1521). */
    private function isSourceBundlerRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }

        return str_ends_with($lower, '\\web\\sourcebundler::bundleforaot');
    }

    private function isSuperglobalsRealLoweringMethod(string $lower): bool
    {
        if (str_ends_with($lower, '\\web\\superglobals::readrequestbody')
            || str_ends_with($lower, '\\web\\superglobals::exportcgienvironment')) {
            return true;
        }

        return $this->shouldUseM3CompileDriverRealLowering()
            && str_contains($lower, '\\web\\superglobals::');
    }

    private function isSuperglobalsM3CompileDriverLoweringMethod(string $lower): bool
    {
        return $this->isSuperglobalsRealLoweringMethod($lower);
    }

    /**
     * ConstStringFolder real LLVM lowering during M3 compile-driver link (#816, #2827).
     *
     * Entry points plus private helpers they call must be real-lowered together; stubbed callees
     * return null and break __DIR__/__FILE__ include-path folding in bin/compile.php bundles.
     */
    private function isConstStringFolderRealLoweringMethod(string $lower): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        foreach ([
            'fold',
            'foldconcat',
            'foldforinclude',
            'tryparsedeployinclude',
            'literalstringvalue',
            'magicscriptconstvalue',
            'sourcedir',
            'findmagicscriptconstforoperand',
            'findmagicscriptconstinblocktree',
            'findconcatforoperand',
            'findconcatinblocktree',
            'folddeploypathconcat',
        ] as $suffix) {
            if (str_ends_with($lower, '\\web\\conststringfolder::'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function collectStubFunctionArgTypes(Block $block): array
    {
        $args = [];
        if (null === $block->func) {
            return $args;
        }
        if ($this->instanceMethodUsesThis($block)) {
            $args[] = $this->context->getTypeFromString('__object__*');
        }
        foreach ($block->func->params as $param) {
            $args[] = $this->llvmTypeForCfgParam($param);
        }
        return $args;
    }

    /**
     * Self-host: CFG Operand params must use __object__* at call sites (#1056).
     *
     * @param list<PHPLLVM\Type> $args
     *
     * @return list<PHPLLVM\Type>
     */
    private function normalizeSelfHostNativeCallArgTypes(array $args, string $logicalName): array
    {
        if (!$this->shouldUseSelfHostJitStubs()) {
            return $args;
        }
        $lower = strtolower($logicalName);
        if (
            !str_contains($lower, 'operandschainequal')
            && !str_contains($lower, 'unwrapoperandchain')
            && !str_contains($lower, 'operandhasobjecttype')
        ) {
            return $args;
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        foreach ($args as $i => $argType) {
            if ('__value__*' === $this->context->getStringFromType($argType)) {
                $args[$i] = $objectPtr;
            }
        }

        return $args;
    }

    /**
     * CFG/compiler Operand handles use native object pointers, not nullable __value__* (#1056).
     */
    private function isCfgObjectIdentityParamType(Type $type): bool
    {
        if (Type::TYPE_OBJECT !== $type->type) {
            return false;
        }
        $name = strtolower($type->classname ?? '');

        return str_contains($name, 'operand') || str_contains($name, '\\op\\');
    }

    private function isCfgOperandDeclaredName(string $name): bool
    {
        $lc = strtolower(ltrim($name, '\\'));

        return 'operand' === $lc
            || str_ends_with($lc, '\\operand')
            || 'temporary' === $lc
            || str_ends_with($lc, '\\temporary');
    }

    private function declaredTypeFromCfgParam(\PHPCfg\Op\Expr\Param $param): ?Type
    {
        if ($param->declaredType instanceof Op\Type\Literal) {
            if ($this->isCfgOperandDeclaredName($param->declaredType->name)) {
                return Type::object('PHPCfg\\Operand');
            }

            return Type::fromDecl($param->declaredType->name);
        }
        if ($param->declaredType instanceof Op\Type\Reference && null !== $param->declaredType->declaration) {
            $inner = $param->declaredType->declaration;
            if ($inner instanceof \PHPCfg\Operand\Literal) {
                return Type::fromDecl($inner->value);
            }
            if ($inner instanceof Op\Type\Literal) {
                if ($this->isCfgOperandDeclaredName($inner->name)) {
                    return Type::object('PHPCfg\\Operand');
                }

                return Type::fromDecl($inner->name);
            }
            try {
                return Type::fromTypeDecl($inner);
            } catch (\LogicException) {
                return null;
            }
        }
        if (null !== $param->declaredType) {
            try {
                return Type::fromTypeDecl($param->declaredType);
            } catch (\LogicException) {
                return null;
            }
        }

        return null;
    }

    private function llvmTypeForCfgParam(\PHPCfg\Op\Expr\Param $param): PHPLLVM\Type
    {
        if ($param->variadic) {
            return $this->context->getTypeFromString('__hashtable__*');
        }
        if ($param->declaredType instanceof Op\Type\Literal
            && 'mixed' === strtolower($param->declaredType->name)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        if ($param->declaredType instanceof Op\Type\Literal
            && $this->isCfgOperandDeclaredName($param->declaredType->name)
        ) {
            return $this->context->getTypeFromString('__object__*');
        }
        $declared = $this->declaredTypeFromCfgParam($param);
        if (null !== $declared && $this->isCfgObjectIdentityParamType($declared)) {
            return $this->context->getTypeFromString('__object__*');
        }
        $rawType = $this->rawTypeFromCfgParam($param);
        if ($this->isCfgObjectIdentityParamType($rawType)) {
            return $this->context->getTypeFromString('__object__*');
        }
        $callback = $this->callbackTypeFromPhptype($rawType);
        if (null !== $callback) {
            return $this->context->getTypeFromString($callback);
        }

        return $this->context->getTypeFromType($rawType);
    }

    /** Stub VM hot-path methods whose opcode switches crash LLVM 9 during self-host AOT (#816). */
    private function compileSkippedVmHotPathStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $args = $this->collectStubFunctionArgTypes($block);
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? 'void';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->emitSelfHostStubReturn($callbackType, $func, VM::SUCCESS);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Stub Compiler::compileCfgBranch() for LLVM 9 self-host AOT (#816). */
    private function compileSkippedCompilerCfgBranchStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($objectPtr, false, $objectPtr, $objectPtr, $objectPtr)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr, $objectPtr],
            []
        );

        return $func;
    }

    /** Thin native LLVM bridge for compile_smoke_m3_emit when emit-helper link is active (#1983). */
    private function compileBootstrapCompileSmokeM3EmitNative(
        string $internalName,
        Block $block,
        string $logicalName
    ): PHPLLVM\Value {
        $this->compileM3EmitTuRuntimeSpineDecls();
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $logPrefix = str_contains($lcname, 'runtime_compile_smoke_m3_emit')
            ? 'runtime_compile_smoke_m3_emit'
            : 'compile_smoke_m3_emit';
        \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emit(
            $this->context,
            $func->getParam(0),
            $func->getParam(1),
            $logPrefix
        );
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'int64';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$strPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Native {main} for M3 emit TU — libc getenv + emit bridge after spine pre-lower (#1937, #2550). */
    private function compileM3EmitTuMainNative(string $internalName, Block $block, ?string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName ?? '{main}');
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if (!$this->m3EmitTuRuntimeSpineLowered) {
            $this->m3EmitTuRuntimeSpineLowered = true;
            $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
            foreach (['parse', 'compileemitsmoke', 'standalone'] as $methodLc) {
                if ('standalone' === $methodLc && $sidecar) {
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
            }
            $this->runQueue();
            $this->compileM3EmitTuRuntimeSpineDecls();
        }
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $logPrefix = getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if (!is_string($logPrefix) || '' === $logPrefix) {
            $logPrefix = 'compile_smoke_m3_emit';
        }
        \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'int64';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

        return $func;
    }

    /** Native {main} for M3 compile_driver bundles — avoids LLVM 9 crash lowering Runtime ctor in PHP CFG (#1768). */
    private function compileM3CompileDriverMainNative(string $internalName, Block $block, ?string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName ?? '{main}');
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $i64 = $this->context->getTypeFromString('int64');
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($i64, false)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        if ($this->shouldUseM3InventoryEmitDriver()) {
            if (!$this->m3CompileDriverRuntimeSpineLowered) {
                $this->m3CompileDriverRuntimeSpineLowered = true;
                $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
                $inventoryEmit = $this->shouldUseM3InventoryEmitDriver();
                foreach (['parse', 'compileemitsmoke', 'standalone'] as $methodLc) {
                    if ('standalone' === $methodLc && ($sidecar || $inventoryEmit)) {
                        continue;
                    }
                    $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
                }
                $this->runQueue();
                $this->compileM3EmitTuRuntimeSpineDecls($this->m3CompileDriverMainBlock);
            }
            $logPrefix = getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
            if (!is_string($logPrefix) || '' === $logPrefix) {
                $logPrefix = 'helloworld_compile_smoke';
            }
            if (null !== $this->m3CompileDriverMainBlock) {
                $standaloneLc = strtolower('PHPCompiler\\Runtime::standalone');
                unset(
                    $this->context->functions[$standaloneLc],
                    $this->context->functionReturnType[$standaloneLc],
                    $this->context->functionProxies[$standaloneLc]
                );
                $this->emitM3EmitTuRuntimeStandaloneStubNative(
                    $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                    'PHPCompiler\\Runtime::standalone',
                    $this->m3CompileDriverMainBlock
                );
            }
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::emitMainEntry($this->context, $logPrefix);
        } else {
            \PHPCompiler\JIT\ValueEchoHelper::echoLiteral($this->context, "compiler_helloworld_compile_driver ready\n");
            $this->context->builder->returnValue($i64->constInt(0, false));
        }

        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'int64';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $logicalName ?? '{main}', [], []);

        return $func;
    }

    /** Lower Runtime/Compiler spine before native emit bridge (#1937, #2512). */
    private function compileM3EmitTuRuntimeSpineDecls(?Block $compileDriverStubBlock = null): void
    {
        $emitTu = $this->shouldUseM3EmitTuNativeBridge() && null !== $this->m3EmitTuMainBlock;
        $compileDriver = $this->shouldUseM3CompileDriverMainNative() && null !== $compileDriverStubBlock;
        if (!$emitTu && !$compileDriver) {
            return;
        }
        $stubBlock = $emitTu ? $this->m3EmitTuMainBlock : $compileDriverStubBlock;
        if ($this->shouldUseM3CompileDriverRealLowering()) {
            $sidecar = $emitTu && $this->isM3EmitTuTrivialEchoSidecarActive();
            $this->compileM3EmitTuRuntimeSpineMethodsForRealLowering();
            foreach (['initparsepipeline', 'initcompiler', 'initvmcontext', 'loadcoremodules', 'standalone'] as $methodLc) {
                if ('standalone' === $methodLc && ($sidecar || $this->shouldUseM3InventoryEmitDriver())) {
                    if (null !== $stubBlock) {
                        if ($this->shouldUseM3InventoryEmitDriver()) {
                            $standaloneLc = strtolower('PHPCompiler\\Runtime::standalone');
                            unset(
                                $this->context->functions[$standaloneLc],
                                $this->context->functionReturnType[$standaloneLc],
                                $this->context->functionProxies[$standaloneLc]
                            );
                        }
                        $this->emitM3EmitTuRuntimeStandaloneStubNative(
                            $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                            'PHPCompiler\\Runtime::standalone',
                            $stubBlock
                        );
                    }
                    continue;
                }
                $this->compileM3EmitTuRuntimeMethodFromQueue($methodLc);
            }
            if ($emitTu) {
                $this->compileM3EmitTuCompilerSpineMethodsFromMainBlock(['compileemitsmoke']);
            } else {
                $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');
            }
            $this->runQueue();
            if (null !== $stubBlock && ($emitTu || $compileDriver)) {
                $this->ensureM3EmitTuRuntimeInitSpineSymbols($stubBlock);
                $this->ensureM3EmitTuEmitBridgeSpineSymbols();
            }
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);

            return;
        }
        if (!$emitTu) {
            return;
        }
        $this->compileM3EmitTuRuntimeSpineMethodsForRealLowering();
        if ($this->shouldUseM3EmitTuEmitHelperSpineRealLowering()) {
            foreach (['initparsepipeline', 'initcompiler', 'loadcoremodules'] as $methodLc) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $this->emitM3EmitTuRuntimeInitVoidStub(
                    $this->llvmInternalName($logical),
                    $logical,
                    $stubBlock
                );
            }
        }
        $this->emitM3EmitTuRuntimeParseStubNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::parse'),
            'PHPCompiler\\Runtime::parse',
            $stubBlock
        );
        $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::compileEmitSmoke'),
            'PHPCompiler\\Runtime::compileEmitSmoke',
            $stubBlock
        );
        $this->emitM3EmitTuRuntimeConstructNativeFunction(
            $this->llvmInternalName('PHPCompiler\\Runtime::__construct'),
            'PHPCompiler\\Runtime::__construct',
            $stubBlock
        );
        $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
            'parseandcompile' => true,
            'parseandcompileemitsmoke' => true,
        ]);
        $this->emitM3EmitTuRuntimeBlockPtrStubNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::compile'),
            'PHPCompiler\\Runtime::compile',
            $stubBlock
        );
        $this->emitM3EmitTuRuntimeStandaloneStubNative(
            $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
            'PHPCompiler\\Runtime::standalone',
            $stubBlock
        );
        $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();
        $this->runQueue();
    }

    /**
     * After spine pre-lower, register emit-bridge decls that call real Runtime::parse (#2512, #2550).
     */
    private function finalizeM3EmitTuRuntimeSpineAfterQueue(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && null !== $this->m3EmitTuMainBlock) {
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();

            return;
        }
        if ($this->shouldUseM3InventoryEmitDriver() && null !== $this->m3CompileDriverMainBlock) {
            $this->compileM3EmitTuRuntimeParseAndCompileNativeDecl([
                'parseandcompile' => true,
                'parseandcompileemitsmoke' => true,
            ]);
            $this->compileM3EmitTuCompilerEmitSmokeNativeDecl();
        }
    }

    /**
     * Register native Runtime parseAndCompile* for emit TU stub bridge (#2516).
     *
     * @param array<string, true> $methods lowercase method names
     */
    private function compileM3EmitTuRuntimeParseAndCompileNativeDecl(array $methods): void
    {
        if ([] === $methods) {
            return;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return;
        }
        $this->context->pushScope();
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
        $this->context->scope->className = 'phpcompiler\\runtime';
        foreach (array_keys($methods) as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if (isset($this->context->functions[$lc])) {
                continue;
            }
            \PHPCompiler\JIT\BootstrapCompileSmokeM3Emit::declareRuntimeParseAndCompileNative(
                $this->context,
                $this->llvmInternalName($logical),
                $logical
            );
        }
        $this->context->popScope();
    }

    /**
     * Pre-lower emit spine before native emit bridge (#2550, #2559).
     *
     * Compile-driver path: host-lowers Runtime::__construct/parse/compileEmitSmoke from modules.
     * Emit-helper path: link-time trivial-echo AOT sidecar for parseAndCompile* / standalone.
     */
    private function compileM3EmitTuRuntimeSpineMethodsForRealLowering(): void
    {
        $sidecar = $this->isM3EmitTuTrivialEchoSidecarActive();
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        foreach ([
            '__construct',
            'parse',
            'compile',
            'compileemitsmoke',
            'initparsepipeline',
            'initcompiler',
            'loadcoremodules',
        ] as $methodLc) {
            $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
        }
        if ($sidecar && null !== $this->m3EmitTuMainBlock) {
            $this->emitM3EmitTuRuntimeStandaloneStubNative(
                $this->llvmInternalName('PHPCompiler\\Runtime::standalone'),
                'PHPCompiler\\Runtime::standalone',
                $this->m3EmitTuMainBlock
            );
        }
        $this->runQueue();
    }

    /** Ensure parse + Compiler::compileEmitSmoke exist before emit-bridge LLVM (#2666). */
    private function ensureM3EmitTuEmitBridgeSpineSymbols(): void
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return;
        }
        $parseLc = strtolower('PHPCompiler\\Runtime::parse');
        if (!isset($this->context->functions[$parseLc])) {
            $this->compileM3EmitTuRuntimeMethodFromModules('parse');
        }
        $compilerEmitLc = 'phpcompiler\\compiler::compileemitsmoke';
        if (!isset($this->context->functions[$compilerEmitLc])) {
            $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');
        }
    }

    /**
     * Emit-helper RuntimeEmitTuInit calls these spine symbols; ensure they are defined (#2633).
     */
    private function ensureM3EmitTuRuntimeInitSpineSymbols(Block $stubBlock): void
    {
        foreach (['initparsepipeline', 'loadcoremodules'] as $methodLc) {
            $logical = 'PHPCompiler\\Runtime::'.$methodLc;
            $lc = strtolower($logical);
            if ($this->shouldUseM3InventoryEmitDriver()) {
                unset($this->context->functions[$lc], $this->context->functionReturnType[$lc], $this->context->functionProxies[$lc]);
            } elseif (!isset($this->context->functions[$lc])) {
                $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
            }
            if (!$this->shouldUseM3InventoryEmitDriver() && isset($this->context->functions[$lc])) {
                continue;
            }
            if ('initparsepipeline' === $methodLc) {
                $this->compileRuntimeInitParsePipelineM3Native(
                    $this->llvmInternalName($logical),
                    $stubBlock,
                    $logical
                );
                continue;
            }
            $this->compileRuntimeLoadCoreModulesM3Native(
                $this->llvmInternalName($logical),
                $stubBlock,
                $logical
            );
        }
    }

    /** Link-time trivial-echo AOT sidecar for emit-helper TU (#2559, #2566). */
    private function isM3EmitTuTrivialEchoSidecarActive(): bool
    {
        if (!$this->shouldUseEmitHelperLinkStubs()) {
            return false;
        }
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return false;
        }
        $this->cacheM3EmitTuTrivialEchoAtLinkTime();

        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context);
    }

    /** Host-compile emit-helper probe source and cache linked AOT bytes at link time (#2559, #2567, #2618). */
    private function cacheM3EmitTuTrivialEchoAtLinkTime(): void
    {
        if ($this->m3EmitTuSidecarsCached) {
            return;
        }
        $this->m3EmitTuSidecarsCached = true;
        $logPrefix = getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if ('helloworld_compile_smoke' === $logPrefix || $this->shouldUseM3InventoryEmitDriver()) {
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../examples/000-HelloWorld/example.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/bootstrap-aot/compiler_smoke_standalone.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_SMOKE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileSmokeSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/selfhost/compiler_helloworld_smoke/compile_driver.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileDriverSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../lib/Compiler.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_PHP_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerPhpSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../bin/compile.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binCompileSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../bin/vm.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binVmSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../src/cli_driver.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::CLI_DRIVER_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::cliDriverSentinelBlock',
                true
            );
        } elseif ('compile_smoke_m3_emit' === $logPrefix) {
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../examples/000-HelloWorld/example.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/bootstrap-aot/compiler_smoke_standalone.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_SMOKE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileSmokeSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/selfhost/compiler_lib_spine_smoke/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerLibSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_UNIT_PROBE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerUnitProbeSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/selfhost/jit_unit_probe/jit_unit_probe_compile.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::JIT_UNIT_PROBE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::jitUnitProbeSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/selfhost/compiler_helloworld_smoke/compile_driver.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileDriverSentinelBlock'
            );
            // M5 inventory emit via selfhost-helloworld-emit (#2666, #2681): mirror helloworld_compile_smoke branch.
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../lib/Compiler.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_PHP_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerPhpSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../bin/compile.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binCompileSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../bin/vm.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binVmSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../src/cli_driver.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::CLI_DRIVER_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::cliDriverSentinelBlock',
                true
            );
        } else {
            $this->registerM3EmitTuSidecarFromPath(
                __DIR__.'/../test/bootstrap-aot/runtime_trivial_echo.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::TRIVIAL_ECHO_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::sentinelBlock'
            );
        }
    }

    /** Host-compile one probe source and register link-time AOT sidecar bytes (#2559, #2618). */
    private function registerM3EmitTuSidecarFromPath(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        bool $sidecarHostStubNonLiteralIncludes = false
    ): void {
        if (!is_readable($path)) {
            return;
        }
        $code = file_get_contents($path);
        if (!is_string($code) || '' === $code) {
            return;
        }
        if (null === $this->m3EmitTuTrivialEchoSource) {
            $this->m3EmitTuTrivialEchoSource = $code;
            $this->context->m3EmitTuTrivialEchoSource = $code;
            $this->context->m3EmitTuTrivialEchoPath = $path;
        }
        // Sidecar-only: avoid host compileEmitSmoke in emit TU LLVM module (#2540).
        $tmpOut = sys_get_temp_dir().'/m3_emit_sidecar_aot_'.getmypid().'_'.substr(md5($sidecarRel), 0, 8);
        @unlink($tmpOut);
        $repoRoot = dirname(__DIR__);
        $compileCmd = 'php '.escapeshellarg($repoRoot.'/bin/compile.php')
            .' -o '.escapeshellarg($tmpOut)
            .' '.escapeshellarg($path);
        $compileEnv = $_ENV;
        // Self-host skips cli/vendor includes during link; M3 compile-driver Runtime ctor native (#2600, #2633).
        $compileEnv['PHP_COMPILER_SELFHOST_AOT'] = '1';
        $compileEnv['PHP_COMPILER_M3_COMPILE_DRIVER'] = '1';
        if ($sidecarHostStubNonLiteralIncludes) {
            $compileEnv['PHP_COMPILER_M3_SIDECAR_HOST'] = '1';
        }
        unset($compileEnv['PHP_COMPILER_EMIT_HELPER_LINK'], $compileEnv['PHP_COMPILER_M3_EMIT_TU']);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($compileCmd, $descriptor, $pipes, $repoRoot, $compileEnv);
        if (!is_resource($proc)) {
            return;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if (0 !== $exit || !is_readable($tmpOut)) {
            if (is_string($stderr) && '' !== $stderr) {
                $tail = strlen($stderr) > 8000 ? substr($stderr, -8000) : $stderr;
                fwrite(
                    STDERR,
                    "m3-emit-tu sidecar host-compile failed: exit={$exit} source={$path} sidecar={$sidecarRel}\n".$tail."\n"
                );
            } else {
                fwrite(
                    STDERR,
                    "m3-emit-tu sidecar host-compile failed: exit={$exit} source={$path} sidecar={$sidecarRel}\n"
                );
            }
            @unlink($tmpOut);

            return;
        }
        $aotBytes = file_get_contents($tmpOut);
        @unlink($tmpOut);
        if (!is_string($aotBytes) || '' === $aotBytes) {
            return;
        }
        \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
            $this->context,
            $repoRoot,
            $code,
            $aotBytes,
            $sidecarRel,
            $sentinelLogical
        );
    }

    /**
     * Emit TU: native LLVM stubs for Runtime spine — avoid PHP CFG (PHPTypes global ctor; #2540).
     */
    private function compileM3EmitTuRuntimeSpineStub(
        string $internalName,
        Block $block,
        string $logicalName,
        string $lower
    ): PHPLLVM\Value {
        if (str_ends_with($lower, '\\runtime::__construct')) {
            return $this->emitM3EmitTuRuntimeConstructNativeFunction($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::initvmcontext')) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (
            str_ends_with($lower, '\\runtime::initparsepipeline')
            || str_ends_with($lower, '\\runtime::initcompiler')
            || str_ends_with($lower, '\\runtime::loadcoremodules')
            || str_ends_with($lower, '\\runtime::loadjit')
            || str_ends_with($lower, '\\runtime::jitcompileblock')
            || str_ends_with($lower, '\\runtime::jitemitinplace')
        ) {
            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::parse')) {
            return $this->emitM3EmitTuRuntimeParseStubNative($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::compileemitsmoke')) {
            return $this->emitM3EmitTuRuntimeCompileEmitSmokeNative($internalName, $logicalName, $block);
        }
        if (str_ends_with($lower, '\\runtime::standalone')) {
            return $this->emitM3EmitTuRuntimeStandaloneStubNative($internalName, $logicalName, $block);
        }
        if (
            str_ends_with($lower, '\\runtime::parseandcompile')
            || str_ends_with($lower, '\\runtime::parseandcompileemitsmoke')
        ) {
            return $this->compileRuntimeParseAndCompileM3Native($internalName, $block, $logicalName);
        }
        if (
            str_ends_with($lower, '\\runtime::compile')
            || str_ends_with($lower, '\\runtime::parseandcompilefile')
        ) {
            return $this->emitM3EmitTuRuntimeBlockPtrStubNative($internalName, $logicalName, $block);
        }

        throw new \LogicException('Unhandled M3 emit TU Runtime spine: '.$logicalName);
    }

    /** Stub Runtime::parse for emit TU link — Batch A replaces with real parser (#2516). */
    private function emitM3EmitTuRuntimeParseStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, $objectPtr, $strPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $strPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Stub Runtime methods returning ?Block for emit TU link (#2540). */
    private function emitM3EmitTuRuntimeBlockPtrStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, ...$args)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Native Runtime::compileEmitSmoke — reuse Compiler emit-smoke block stub (#2442). */
    private function emitM3EmitTuRuntimeCompileEmitSmokeNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objectPtr, false, $objectPtr, $objectPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objectPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = '__object__*';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Stub Runtime::standalone for emit TU link — Batch A replaces (#2516). */
    private function emitM3EmitTuRuntimeStandaloneStubNative(
        string $internalName,
        string $logicalName,
        Block $block
    ): PHPLLVM\Value {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        $objectPtr = $this->context->getTypeFromString('__object__*');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $voidTy = $this->context->getTypeFromString('void');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($voidTy, false, $objectPtr, $objectPtr, $strPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::isRegistered($this->context)) {
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::emitStandaloneWriteCachedAot(
                $this->context,
                $func->getParam(1),
                $func->getParam(2)
            );
        } else {
            $this->context->builder->returnVoid();
        }
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = 'void';
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            [$objectPtr, $objectPtr, $strPtr],
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    /** Register native compileEmitSmoke with Compiler object metadata (#1937). */
    private function compileM3EmitTuCompilerEmitSmokeNativeDecl(): void
    {
        if (!$this->shouldUseM3EmitTuNativeBridge() && !$this->shouldUseM3InventoryEmitDriver()) {
            return;
        }
        if ($this->shouldUseM3CompileDriverRealLowering()) {
            $this->compileM3EmitTuCompilerMethodFromRuntimeModules('compileemitsmoke');

            return;
        }
        $logical = 'PHPCompiler\\Compiler::compileEmitSmoke';
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        $this->context->pushScope();
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Compiler');
        $this->context->scope->className = 'phpcompiler\\compiler';
        $this->emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
            $this->llvmInternalName($logical),
            $logical
        );
        $this->context->popScope();
    }

    /**
     * Pre-lower selected Compiler methods from the bundled emit TU (#1937).
     *
     * @param list<string> $methodLcs lowercase method names without class prefix
     */
    private function compileM3EmitTuCompilerSpineMethodsFromMainBlock(array $methodLcs): void
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return;
        }
        foreach ($methodLcs as $methodLc) {
            $this->compileM3EmitTuCompilerMethodFromRuntimeModules($methodLc);
        }
        if (null === $this->m3EmitTuMainBlock) {
            return;
        }
        $allowed = array_fill_keys($methodLcs, true);
        foreach ($this->m3EmitTuMainBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $nameOp = $this->m3EmitTuMainBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            $lc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
            if ('phpcompiler\\compiler' !== $lc || null === $op->block1) {
                continue;
            }
            $this->context->pushScope();
            $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
            $this->context->scope->className = $lc;
            foreach ($op->block1->opCodes as $methodOp) {
                if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                    continue;
                }
                $methodOpName = $op->block1->getOperand($methodOp->arg1);
                if (!$methodOpName instanceof Operand\Literal) {
                    continue;
                }
                $methodLc = strtolower($methodOpName->value);
                if (!isset($allowed[$methodLc])) {
                    continue;
                }
                $logical = $lc.'::'.$methodLc;
                if (!isset($this->context->functions[strtolower($logical)])) {
                    $this->compileBlock($methodOp->block1, $logical);
                }
            }
            $this->context->popScope();

            return;
        }
    }

    private function compileM3EmitTuCompilerMethodFromRuntimeModules(string $methodLc): void
    {
        $logical = 'PHPCompiler\\Compiler::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        foreach ($this->context->runtime->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                if (!$func instanceof CoreFunc\PHP) {
                    continue;
                }
                if (strtolower($func->getName()) !== $lc) {
                    continue;
                }
                $this->compileBlock($func->block, $logical);

                return;
            }
        }
        $compilerPath = dirname(__DIR__).'/Compiler.php';
        if (!is_file($compilerPath)) {
            return;
        }
        try {
            $script = $this->context->runtime->parse((string) file_get_contents($compilerPath), $compilerPath);
        } catch (\Throwable $e) {
            return;
        }
        foreach ($script->functions as $cfgFunc) {
            $funcLc = strtolower($cfgFunc->name);
            if ($funcLc !== $lc && $funcLc !== $methodLc && !str_ends_with($funcLc, '\\'.$methodLc)) {
                continue;
            }
            $compiled = $this->context->runtime->compileFunc($logical, $cfgFunc);
            if ($compiled instanceof CoreFunc\PHP) {
                $this->compileBlock($compiled->block, $logical);
            }

            return;
        }
    }

    /** Pre-lower Runtime spine from JIT queue before emit bridge binds symbols (#2512, #2550). */
    private function compileM3EmitTuRuntimeMethodFromQueue(string $methodLc): void
    {
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        foreach ($this->queue as $item) {
            $func = $item[0];
            if (!$func instanceof CoreFunc\PHP) {
                continue;
            }
            if (strtolower($func->getName()) !== $lc) {
                continue;
            }
            $this->compileBlock($func->block, $logical);

            return;
        }
        $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks([$methodLc]);
        $this->compileM3EmitTuRuntimeMethodFromModules($methodLc);
    }

    private function compileM3EmitTuRuntimeMethodFromModules(string $methodLc): void
    {
        if ($this->shouldUseM3EmitTuEmitHelperSpineRealLowering()) {
            return;
        }
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        foreach ($this->context->runtime->modules as $module) {
            foreach ($module->getFunctions() as $func) {
                if (!$func instanceof CoreFunc\PHP) {
                    continue;
                }
                if (strtolower($func->getName()) !== $lc) {
                    continue;
                }
                $this->compileBlock($func->block, $logical);

                return;
            }
        }
        if (null === $this->m3EmitTuMainBlock) {
            return;
        }
        foreach ($this->m3EmitTuMainBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $nameOp = $this->m3EmitTuMainBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            $classLc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
            if ('phpcompiler\\runtime' !== $classLc || null === $op->block1) {
                continue;
            }
            $this->context->pushScope();
            $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
            $this->context->scope->className = $classLc;
            foreach ($op->block1->opCodes as $methodOp) {
                if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                    continue;
                }
                $methodOpName = $op->block1->getOperand($methodOp->arg1);
                if (!$methodOpName instanceof Operand\Literal || strtolower($methodOpName->value) !== $methodLc) {
                    continue;
                }
                if (null !== $methodOp->block1) {
                    $this->compileBlock($methodOp->block1, $logical);
                }
            }
            $this->context->popScope();

            return;
        }
        $runtimePath = dirname(__DIR__).'/Runtime.php';
        if (!is_file($runtimePath)) {
            return;
        }
        try {
            $script = $this->context->runtime->parse((string) file_get_contents($runtimePath), $runtimePath);
        } catch (\Throwable $e) {
            return;
        }
        foreach ($script->functions as $cfgFunc) {
            $funcLc = strtolower($cfgFunc->name);
            if ($funcLc !== $lc && $funcLc !== $methodLc && !str_ends_with($funcLc, '\\'.$methodLc)) {
                continue;
            }
            $compiled = $this->context->runtime->compileFunc($logical, $cfgFunc);
            if ($compiled instanceof CoreFunc\PHP) {
                $this->compileBlock($compiled->block, $logical);
            }

            return;
        }
    }

    /**
     * Find Runtime methods on bundled declare_class blocks (private init* may be absent from queue).
     *
     * @param list<string> $methodLcs lowercase method names without class prefix
     */
    private function compileM3EmitTuRuntimeMethodFromDeclareClassBlocks(array $methodLcs): void
    {
        if (!$this->shouldUseM3EmitTuNativeBridge()) {
            return;
        }
        $allowed = array_fill_keys($methodLcs, true);
        $blocks = [];
        if (null !== $this->m3EmitTuMainBlock) {
            $blocks[] = $this->m3EmitTuMainBlock;
        }
        foreach ($this->queue as $item) {
            $blocks[] = $item[1];
        }
        foreach ($blocks as $block) {
            if (null === $block) {
                continue;
            }
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                    continue;
                }
                $nameOp = $block->getOperand($op->arg1);
                if (!$nameOp instanceof Operand\Literal) {
                    continue;
                }
                $classLc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
                if ('phpcompiler\\runtime' !== $classLc || null === $op->block1) {
                    continue;
                }
                $this->context->pushScope();
                $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                $this->context->scope->className = $classLc;
                foreach ($op->block1->opCodes as $methodOp) {
                    if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                        continue;
                    }
                    $methodOpName = $op->block1->getOperand($methodOp->arg1);
                    if (!$methodOpName instanceof Operand\Literal) {
                        continue;
                    }
                    $methodLc = strtolower($methodOpName->value);
                    if (!isset($allowed[$methodLc])) {
                        continue;
                    }
                    $logical = $classLc.'::'.$methodLc;
                    if (!isset($this->context->functions[strtolower($logical)])) {
                        $this->compileBlock($methodOp->block1, $logical);
                    }
                }
                $this->context->popScope();
            }
        }
    }

    /** Pre-lower Compiler::compile only; callees compile on demand (#1937). */
    private function compileM3EmitTuCompilerCompileDecl(): void
    {
        if (!$this->shouldUseM3EmitTuNativeBridge() || null === $this->m3EmitTuMainBlock) {
            return;
        }
        $logical = 'phpcompiler\\compiler::compile';
        if (isset($this->context->functions[$logical])) {
            return;
        }
        foreach ($this->m3EmitTuMainBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_CLASS !== $op->type) {
                continue;
            }
            $nameOp = $this->m3EmitTuMainBlock->getOperand($op->arg1);
            if (!$nameOp instanceof Operand\Literal) {
                continue;
            }
            $lc = strtolower(str_replace('/', '\\', ltrim($nameOp->value, '\\')));
            if ('phpcompiler\\compiler' !== $lc || null === $op->block1) {
                continue;
            }
            foreach ($op->block1->opCodes as $methodOp) {
                if (OpCode::TYPE_DECLARE_METHOD !== $methodOp->type) {
                    continue;
                }
                $this->context->pushScope();
                $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                $this->context->scope->className = $lc;
                $this->context->popScope();

                return;
            }
        }
    }

    /** Emit TU: native compileEmitSmoke with PHPCfg property typing (#1937). */
    private function emitM3EmitTuCompilerCompileEmitSmokeNativeFunction(
        string $internalName,
        string $logical
    ): PHPLLVM\Value {
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return $this->context->functions[$lc];
        }
        $objPtr = $this->context->getTypeFromString('__object__*');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType($objPtr, false, $objPtr, $objPtr)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->context->builder->returnValue($objPtr->constNull());
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lc] = $func;
        $this->context->functionReturnType[$lc] = '__object__*';
        $this->context->functionProxies[$lc] = new JIT\Call\Native(
            $func,
            $logical,
            [$objPtr, $objPtr],
            []
        );

        return $func;
    }

    /** Stub Compiler CFG helpers that crash LLVM 9 during self-host AOT (#816). */
    private function compileSkippedCompilerSplitCfgStub(string $internalName, Block $block, string $logicalName): PHPLLVM\Value
    {
        $lcname = strtolower($logicalName);
        if (isset($this->context->functions[$lcname])) {
            return $this->context->functions[$lcname];
        }
        if ($this->shouldUseM3EmitTuNativeBridge() && $this->isBootstrapM3RuntimeEmitBridgeName($lcname)) {
            return $this->compileBootstrapCompileSmokeM3EmitNative($internalName, $block, $logicalName);
        }
        if ($this->isM3CompileDriverCompilerNativeLoweringName($lcname)) {
            return JIT\CompilerOperandChainNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->shouldUseM3CompileDriverRealLowering() && JIT\VariableTypeMapNative::isNativeLoweringName($lcname)) {
            return JIT\VariableTypeMapNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if (JIT\OperandNameNative::isNativeLoweringName($lcname)) {
            return JIT\OperandNameNative::compile(
                $this->context,
                $this->llvmInternalName($internalName),
                $block,
                $logicalName
            );
        }
        if ($this->isM3CompileDriverCompilerPhpLoweringName($lcname)) {
            return $this->compileRuntimeSpinePhpLowering($internalName, $block, $logicalName);
        }
        $args = $this->normalizeSelfHostNativeCallArgTypes(
            $this->collectStubFunctionArgTypes($block),
            $logicalName
        );
        $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__object__*';
        $returnType = $this->context->getTypeFromString($callbackType);
        $func = $this->context->module->addFunction(
            $this->llvmInternalName($internalName),
            $this->context->context->functionType($returnType, false, ...$args)
        );
        $bb = $func->appendBasicBlock('stub');
        $saved = $this->context->builder;
        $this->context->builder = $this->context->context->builderCreate();
        $this->context->builder->positionAtEnd($bb);
        $this->emitSelfHostStubReturn($callbackType, $func);
        $this->context->builder->clearInsertionPosition();
        $this->context->builder = $saved;
        $this->context->functions[$lcname] = $func;
        $this->context->functionReturnType[$lcname] = $callbackType;
        $this->context->functionProxies[$lcname] = new JIT\Call\Native(
            $func,
            $logicalName,
            $args,
            $this->collectParamDefaults($block)
        );

        return $func;
    }

    public function compileSubBlock(
        PHPLLVM\Value $func,
        Block $block,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, null, ...$args);
    }

    /**
     * Inline an included compilation unit at a dedicated entry block (issue #568 / MiniWebApp templates).
     */
    public function compileIncludedAtEntry(
        PHPLLVM\Value $func,
        Block $block,
        PHPLLVM\BasicBlock $entryBlock
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        $this->context->inlineIncludeExitBlock = null;
        $exit = $this->compileBlockInternal($func, $block, $limit, $entryBlock);
        if (null !== $this->context->inlineIncludeExitBlock) {
            $exit = $this->context->inlineIncludeExitBlock;
        }

        return $exit;
    }
    
    private function compileBlockInternal(
        PHPLLVM\Value $func,
        Block $block,
        ?int $limit = null,
        ?PHPLLVM\BasicBlock $entryBlock = null,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        if ($this->context->scope->blockStorage->contains($block)) {
            return $this->context->scope->blockStorage[$block];
        }
        if (null !== $block->func) {
            JIT\Progress::noteFunction($block->func->getScopedName());
        }
        if (null !== $entryBlock) {
            $origBasicBlock = $basicBlock = $entryBlock;
        } else {
            self::$blockNumber++;
            $origBasicBlock = $basicBlock = $func->appendBasicBlock('block_' . self::$blockNumber);
        }
        $this->context->scope->blockStorage[$block] = $basicBlock;
        $builder = $this->context->builder;
        $builder->positionAtEnd($basicBlock);
        if ([] !== $args) {
            $this->context->implicitThisArgument = null;
        }
        // Handle hoisted variables
        foreach ($block->orig->hoistedOperands as $operand) {
            if ($this->context->coalesceAssignTargets->contains($operand)) {
                continue;
            }
            $this->context->makeVariableFromOp($func, $basicBlock, $block, $operand);
        }

        $thisParamOffset = 0;
        if (null !== $block->func && $block->orig === $block->func->cfg) {
            $this->context->jitEnclosingBlock = $block;
        }
        if ([] !== $args) {
            if ($this->instanceMethodUsesThis($block)) {
                $thisParamOffset = 1;
            }
            foreach ($block->orig->hoistedOperands as $hoisted) {
                if ('this' === JIT\OperandName::resolve($hoisted)) {
                    if (!$this->context->hasVariableOp($hoisted)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $hoisted);
                    }
                    $this->assignOperand($hoisted, $args[0], true);
                    $thisParamOffset = 1;
                    break;
                }
            }
            if (1 === $thisParamOffset) {
                $this->context->implicitThisArgument = $args[0];
            } else {
                $this->context->implicitThisArgument = null;
            }
            // Only the CFG entry block receives LLVM arguments; branch blocks share the same func (#210).
            if (null !== $block->func && $block->orig === $block->func->cfg) {
                foreach ($block->func->params as $idx => $param) {
                    $argIdx = $thisParamOffset + $idx;
                    if ($param->variadic) {
                        $remaining = array_slice($args, $argIdx);
                        $packed = [] === $remaining
                            ? JIT\HashTableHelper::emptyVariable($this->context)
                            : JIT\HashTableHelper::packVariables($this->context, $remaining);
                        if (!$this->context->hasVariableOp($param->result)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $param->result);
                        }
                        $this->assignOperand($param->result, $packed, true);
                        break;
                    }
                    if ($argIdx >= count($args)) {
                        break;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $param->result);
                    }
                    $this->assignOperand($param->result, $args[$argIdx], true);
                }
            }
        }

        for ($i = 0, $length = null !== $limit ? $limit : count($block->opCodes); $i < $length; $i++) {
            $op = $block->opCodes[$i];
            if (
                null !== $block->func
                && '{main}' === $block->func->name
            ) {
                JIT\Progress::noteFunction('{main}:op='.$i.':type='.$op->type);
            }
            switch ($op->type) {
                case OpCode::TYPE_ARG_RECV:
                    $recvSlot = $op->arg2 + $thisParamOffset;
                    $isVariadicSlot = null !== $block->variadicParamIndex
                        && $block->variadicParamIndex === (int) $op->arg2;
                    if ($isVariadicSlot) {
                        $packed = isset($args[$recvSlot])
                            ? $args[$recvSlot]
                            : JIT\HashTableHelper::emptyVariable($this->context);
                        $this->assignOperand($block->getOperand($op->arg1), $packed, true);
                        break;
                    }
                    if (!isset($args[$recvSlot])) {
                        throw new \LogicException('Missing required argument ' . $op->arg2);
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $args[$recvSlot]);
                    break;
                case OpCode::TYPE_ASSIGN:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $destOp = $block->getOperand($op->arg1);
                    $forceCoalesce = $this->context->coalesceAssignTargets->contains($destOp);
                    $forceAssign = $forceCoalesce
                        || $this->assignOperandsUsedByLiteralInclude($block, $op);
                    $this->assignOperand($block->getOperand($op->arg2), $value, $forceAssign);
                    $this->assignOperand($destOp, $value, $forceAssign);
                    foreach ([$block->getOperand($op->arg2), $destOp] as $destOperand) {
                        if (!$this->context->hasVariableOp($destOperand)) {
                            continue;
                        }
                        $destVar = $this->context->getVariableFromOp($destOperand);
                        $this->foldCompileTimeStringFromAssign(
                            $block,
                            (int) $op->arg3,
                            $destVar,
                            $value
                        );
                    }
                    break;  
                case OpCode::TYPE_ASSIGN_REF:
                    $destOp = $block->getOperand($op->arg1);
                    $srcOp = $block->getOperand($op->arg2);
                    $destName = JIT\OperandName::resolve($destOp);
                    $srcName = JIT\OperandName::resolve($srcOp);
                    if (null === $destName) {
                        throw new \LogicException('Reference assignment requires named destination variable');
                    }
                    if (null !== $srcName) {
                        if ($this->context->hasVariableOp($srcOp)) {
                            $srcVar = $this->context->getVariableFromOp($srcOp);
                            $this->context->bindVariableByName($destName, $srcVar);
                            $this->context->setVariableOp($destOp, $srcVar);
                            break;
                        }
                        $this->context->refAliasNames[$destName] = $this->context->resolveRefAliasName($srcName);
                        break;
                    }
                    if (!$this->context->hasVariableOp($srcOp)) {
                        throw new \LogicException('Reference assignment requires a bound source variable');
                    }
                    $srcVar = $this->context->getVariableFromOp($srcOp);
                    $this->context->bindVariableByName($destName, $srcVar);
                    $this->context->setVariableOp($destOp, $srcVar);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $block->constants[$op->arg2]->toString();
                    $globalVar = $this->ensureJitGlobal($globalName);
                    $this->context->bindVariableByName($globalName, $globalVar);
                    $this->context->setVariableOp(
                        $block->getOperand($op->arg1),
                        $globalVar
                    );
                    break;
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                    if (!isset($block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $storageKey = $block->constants[$op->arg2]->toString();
                    $destOp = $block->getOperand($op->arg1);
                    if (!$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    $staticVar = $this->ensureJitFunctionStatic($storageKey);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        JIT\FunctionStaticHelper::emitLazyInit(
                            $this->context,
                            $storageKey,
                            $staticVar,
                            $this->jitVariableFromVmConstant($block->constants[$op->arg3])
                        );
                    }
                    $this->context->setVariableOp($destOp, $staticVar);
                    break;
                case OpCode::TYPE_VAR_FETCH:
                    $destOp = $block->getOperand($op->arg1);
                    if (!$this->context->hasVariableOp($destOp)) {
                        $this->context->makeVariableFromOp($func, $basicBlock, $block, $destOp);
                    }
                    $nameSlot = (int) $op->arg2;
                    foreach ($block->scopedOperands() as $slotOp) {
                        if ($block->slotForOperand($slotOp) === $nameSlot && !$this->context->hasVariableOp($slotOp)) {
                            $this->context->makeVariableFromOp($func, $basicBlock, $block, $slotOp);
                        }
                    }
                    $nameVar = $this->variableFromBlockSlot($block, $nameSlot);
                    $this->foldVarFetchNameFromAssign($block, $nameSlot, $nameVar);
                    $target = JIT\VarFetchHelper::resolveTarget($this->context, $block, $nameVar);
                    if ($this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1)) {
                        $this->context->setVariableOp($destOp, $target);
                    } else {
                        $this->assignOperand($destOp, $target, true);
                    }
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type;
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $resultOp = $block->getOperand($op->arg1);
                    if (null === $op->arg3) {
                        if (Variable::TYPE_STRING === $value->type) {
                            throw new \LogicException('[] is only supported for arrays');
                        }
                        $this->context->setVariableOp(
                            $resultOp,
                            JIT\HashTableHelper::reserveAppendSlot($this->context, $value)
                        );
                        break;
                    }
                    $dimOp = $block->getOperand($op->arg3);
                    $dim = $this->context->getVariableFromOp($dimOp);
                    $containerOp = $block->getOperand($op->arg2);
                    $containerUserType = $containerOp->type->userType ?? '';
                    if (
                        $value->type === Variable::TYPE_OBJECT
                        && 'splobjectstorage' === strtolower($containerUserType)
                        && Variable::TYPE_OBJECT === $dim->type
                    ) {
                        $ht = $this->context->type->object->splBackingHashtable($value);
                        $htVal = $this->context->helper->loadValue($ht);
                        $keyObj = $this->context->helper->loadValue($dim);
                        if ($forWrite) {
                            $fetched = JIT\HashTableHelper::writableObjectKeyValueBox(
                                $this->context,
                                $htVal,
                                $keyObj
                            );
                            $this->context->setVariableOp($resultOp, $fetched);
                        } else {
                            $fetched = JIT\HashTableHelper::readObjectKeyToValueBox(
                                $this->context,
                                $htVal,
                                $keyObj
                            );
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if ($value->type === Variable::TYPE_STRING) {
                        $charPtr = JIT\StringOffsetHelper::dimFetch(
                            $this->context,
                            $value->value,
                            $dim
                        );
                        if ($forWrite) {
                            $this->context->makeVariableFromValueOp($charPtr, $resultOp);
                        } else {
                            $str = JIT\StringOffsetHelper::readAsString($this->context, $charPtr);
                            $this->context->makeVariableFromValueOp($str, $resultOp);
                        }
                        break;
                    }
                    if ($value->type === Variable::TYPE_HASHTABLE) {
                        $fetched = $value->dimFetch($dim, $resultOp->type, $forWrite);
                        if ($forWrite) {
                            $this->context->setVariableOp($resultOp, $fetched);
                        } else {
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if ($value->type & Variable::IS_NATIVE_ARRAY && $this->context->analyzer->needsBoundsCheck($value, $dimOp)) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__nativearray__boundscheck'),
                            $dim->value,
                            $this->context->constantFromInteger($value->nextFreeElement)
                        );
                    }
                    $this->assignOperand(
                        $resultOp,
                        $value->dimFetch($dim, $resultOp->type, $forWrite)
                    );
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    JIT\HashTableHelper::initArray($this->context, $result);
                    if (null !== $op->arg2) {
                        $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                        $key = null !== $op->arg3
                            ? $this->context->getVariableFromOp($block->getOperand($op->arg3))
                            : null;
                        JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                        $this->bumpNativeArrayNextFreeForExplicitIntKey($result, $op->arg3, $block);
                    }
                    break;
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $key = null !== $op->arg3
                        ? $this->context->getVariableFromOp($block->getOperand($op->arg3))
                        : null;
                    JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                    $this->bumpNativeArrayNextFreeForExplicitIntKey($result, $op->arg3, $block);
                    break;
                case OpCode::TYPE_ARRAY_SPREAD:
                    JIT\HashTableHelper::spreadInto(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg1)),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_TYPE_ASSERT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_EMPTY:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $truthy = (new ext\standard\boolval())->call($this->context, $from);
                    $this->assignOperandValue(
                        $block->getOperand($op->arg1),
                        $this->context->builder->not($truthy)
                    );
                    break;
                case OpCode::TYPE_ISSET:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = null !== $dimOp ? $this->context->getVariableFromOp($dimOp) : null;
                    $issetResult = IssetHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $issetResult);
                    break;
                case OpCode::TYPE_ITER_RESET:
                    $arrayOp = $block->getOperand($op->arg1);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    JIT\IteratorHelper::compileReset(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    $valid = JIT\IteratorHelper::compileValid(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    $key = JIT\IteratorHelper::compileKey(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $key);
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    $arrayOp = $block->getOperand($op->arg2);
                    $array = $this->context->getVariableFromOp($arrayOp);
                    if ($op->arg3) {
                        $value = JIT\IteratorHelper::compileValueByRef(
                            $this->context,
                            $array,
                            self::foreachContainerUserType($arrayOp)
                        );
                        $this->context->setVariableOp($block->getOperand($op->arg1), $value);
                        break;
                    }
                    $value = JIT\IteratorHelper::compileValue(
                        $this->context,
                        $array,
                        self::foreachContainerUserType($arrayOp)
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                    if (OpCode::SCRIPT_MAGIC_LINE === (int) $op->arg3) {
                        $line = null !== $op->arg2 ? (int) $op->arg2 : 1;
                        if ($line < 1) {
                            $line = 1;
                        }
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromConstantInt($this->context, $line)
                        );
                    } else {
                        $magicStr = JIT\ScriptMagic::stringForBlock($block, (int) $op->arg3);
                        $lit = new Operand\Literal($magicStr);
                        $lit->type = \PHPTypes\Type::string();
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromLiteral($this->context, $lit)
                        );
                    }
                    break;
                case OpCode::TYPE_INCLUDE:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    JIT\IncludeHelper::compileLiteral(
                        $this,
                        $func,
                        $block,
                        $op,
                        null !== $op->arg2 ? $block->getOperand($op->arg2) : null
                    );
                    break;
                case OpCode::TYPE_CLONE:
                    $srcVar = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if (Variable::TYPE_OBJECT === $srcVar->type) {
                        $srcObj = $this->context->helper->loadValue($srcVar);
                    } elseif (Variable::TYPE_VALUE === $srcVar->type) {
                        $valuePtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $srcVar);
                        $srcObj = $this->context->builder->call(
                            $this->context->lookupFunction('__value__readObject'),
                            $valuePtr
                        );
                    } else {
                        throw new \LogicException('clone requires an object');
                    }
                    $cloned = $this->context->type->object->cloneObject($srcObj);
                    $objVar = new JIT\Variable(
                        $this->context,
                        Variable::TYPE_OBJECT,
                        Variable::KIND_VALUE,
                        $cloned
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $objVar);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if ($from->type === Variable::TYPE_NATIVE_BOOL) {
                        $value = $this->context->helper->loadValue($from);
                    } else {
                        $value = $this->context->castToBool($this->context->helper->loadValue($from));
                    }
                    $__right = $value->typeOf()->constInt(1, false);
                            
                        

                        

                        

                        $result = $this->context->builder->bitwiseXor($value, $__right);
    

                    $this->assignOperandValue($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_CONCAT:
                    if (null === $op->arg2 || null === $op->arg3) {
                        break;
                    }
                    if (!$this->context->hasVariableOp($block->getOperand($op->arg1))) {
                        // don't bother with constant operations
                        break;
                    }
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $left = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $right = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $this->context->type->string->concat($result, $left, $right);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $value = null;
                    if (!is_null($op->arg3)) {
                        // try NS constant fetch
                        $value = $this->context->constantFetch($block->getOperand($op->arg3));
                    }
                    if (is_null($value)) {
                        $value = $this->context->constantFetch($block->getOperand($op->arg2));
                    }
                    if (is_null($value)) {
                        $name = $block->getOperand($op->arg2);
                        $label = $name instanceof Operand\Literal ? (string) $name->value : get_class($name);
                        if (null !== $op->arg3) {
                            $ns = $block->getOperand($op->arg3);
                            if ($ns instanceof Operand\Literal) {
                                $label = (string) $ns->value.'\\'.$label;
                            }
                        }
                        $bundleConst = $this->jitFoldPhpCompilerBundleConstant($label);
                        if (null !== $bundleConst) {
                            $this->assignOperand($block->getOperand($op->arg1), $bundleConst);
                            break;
                        }
                        throw new \RuntimeException('Unknown constant fetch: '.$label);
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    assert($nameOp instanceof Operand\Literal);
                    if ('class' === strtolower($nameOp->value)) {
                        $className = $this->resolveClassNameForPseudoConst($block, $classOp);
                        $lit = new Operand\Literal($className);
                        $lit->type = Type::string();
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\Variable::fromLiteral($this->context, $lit)
                        );
                        break;
                    }
                    if ('native_type_map' === strtolower($nameOp->value) || 'type_map' === strtolower($nameOp->value)) {
                        $classLabel = $classOp instanceof Operand\Literal
                            ? strtolower($classOp->value)
                            : '';
                        if (str_contains($classLabel, 'variable')) {
                            $mapVar = $this->jitVariableArrayClassConstant($nameOp->value);
                            if (null !== $mapVar) {
                                $this->assignOperand($block->getOperand($op->arg1), $mapVar);
                                break;
                            }
                        }
                    }
                    $opcodeConst = $this->jitFoldOpCodeClassConstant($classOp, $nameOp->value);
                    if (null !== $opcodeConst) {
                        $this->assignOperand($block->getOperand($op->arg1), $opcodeConst);
                        break;
                    }
                    $classId = $this->context->type->object->resolveClassId($classOp);
                    $value = $this->context->type->object->classConstFetch($classId, $nameOp->value);
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    $classOp = $block->getOperand($op->arg3);
                    assert($classOp instanceof Operand\Literal);
                    $expr = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $result = $this->context->type->object->emitInstanceOf($expr, $classOp->value);
                    $this->assignOperand($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    if (!$nameOp instanceof Operand\Literal) {
                        throw new \LogicException('JIT static property fetch requires a literal property name');
                    }
                    $classId = $this->context->type->object->resolveClassId($classOp);
                    $this->context->setVariableOp(
                        $block->getOperand($op->arg1),
                        $this->context->type->object->staticPropertyFetch($classId, $nameOp->value)
                    );
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    if (!$nameOp instanceof Operand\Literal) {
                        throw new \LogicException('JIT static property unset requires a literal property name');
                    }
                    $classId = $this->context->type->object->resolveClassId($classOp);
                    $this->context->type->object->staticPropertyUnset($classId, $nameOp->value);
                    break;
                case OpCode::TYPE_UNSET:
                    if (null === $op->arg3) {
                        $targetOp = $block->getOperand($op->arg2);
                        if (
                            !$this->context->hasVariableOp($targetOp)
                            && null === JIT\OperandName::resolve($targetOp)
                        ) {
                            break;
                        }
                        if ($this->context->hasVariableOp($targetOp)) {
                            $target = $this->context->getVariableFromOp($targetOp);
                            if (
                                null !== $target->writableHt
                                && null !== $target->writableStringKey
                                && JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType
                            ) {
                                JIT\HashTableHelper::unsetStringKey(
                                    $this->context,
                                    $target->writableHt,
                                    $target->writableStringKey
                                );
                                break;
                            }
                        }
                        if ($this->context->hasVariableOp($targetOp)) {
                            $this->context->setVariableOp($targetOp, $this->jitNullVariable());
                        }
                    } else {
                        JIT\UnsetHelper::compileOffset($this->context, $block, $op);
                    }
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand($block->getOperand($op->arg1), $value->castTo(Variable::TYPE_NATIVE_BOOL));
                    break;
                case OpCode::TYPE_CAST_INT:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if (Variable::TYPE_VALUE === $value->type) {
                        $ptr = Variable::KIND_VARIABLE === $value->kind
                            ? $value->value
                            : $this->context->helper->loadValue($value);
                        $long = $this->context->builder->call(
                            $this->context->lookupFunction('__value__readLong'),
                            $ptr
                        );
                        $this->assignOperandValue($block->getOperand($op->arg1), $long);
                    } else {
                        $long = (new ext\standard\intval())->call($this->context, $value);
                        $this->assignOperandValue($block->getOperand($op->arg1), $long);
                    }
                    break;
                case OpCode::TYPE_CAST_STRING:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        JIT\JitNativeString::coerce($this->context, $value)
                    );
                    break;
                case OpCode::TYPE_ECHO:
                case OpCode::TYPE_PRINT:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                    $argOffset = $op->type === OpCode::TYPE_ECHO ? $op->arg1 : $op->arg2;
                    $arg = $this->context->getVariableFromOp($block->getOperand($argOffset));
                    if (Variable::KIND_VARIABLE === $arg->kind) {
                        $slotType = $this->context->getStringFromType($arg->value->typeOf());
                        if ('__value__' === $slotType) {
                            JIT\ValueEchoHelper::echo(
                                $this->context,
                                JIT\JitValueBox::pointer($this->context, $arg->value)
                            );
                            break;
                        }
                        if ('__string__' === $slotType && Variable::TYPE_STRING !== $arg->type) {
                            $arg = new Variable(
                                $this->context,
                                Variable::TYPE_STRING,
                                Variable::KIND_VARIABLE,
                                $arg->value
                            );
                        }
                    }
                    switch ($arg->type) {
                        case Variable::TYPE_VALUE:
                            $echoSlot = JIT\JitValueBox::alloc($this->context);
                            JIT\JitValueBox::copyFromPointer(
                                $this->context,
                                $echoSlot,
                                JIT\JitValueBox::valuePtrFromVariable($this->context, $arg)
                            );
                            JIT\ValueEchoHelper::echo(
                                $this->context,
                                JIT\JitValueBox::pointer($this->context, $echoSlot)
                            );
                            break;
                        case Variable::TYPE_STRING:
                            if ($arg->kind === Variable::KIND_VALUE
                                && 'i8*' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                $byte = $this->context->builder->load($arg->value);
                                $this->context->builder->call(
                                    $this->context->lookupFunction('__phpc_ob_echo_char'),
                                    $byte
                                );
                                break;
                            }
                            $argValue = $this->context->helper->loadValue($arg);
                            $offset = $this->context->structFieldIndex($argValue, 'length');
                            $__str__length = $this->context->builder->load(
                                $this->context->builder->structGep($argValue, $offset)
                            );
                            $offset = $this->context->structFieldIndex($argValue, 'value');
                            $__str__value = $this->context->builder->structGep($argValue, $offset);
                            $sizeT = $this->context->getTypeFromString('size_t');
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_substr'),
                                $__str__value,
                                $this->context->builder->zExt($__str__length, $sizeT)
                            );
                            break;
                        case Variable::TYPE_NATIVE_LONG:
                            $argValue = $this->context->helper->loadValue($arg);
                            $i64 = $this->context->getTypeFromString('int64');
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_ll'),
                                $this->context->builder->zExt($argValue, $i64)
                            );
                            break;
                        case Variable::TYPE_NATIVE_DOUBLE:
                            $argValue = $this->context->helper->loadValue($arg);
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_double'),
                                $argValue
                            );
                            break;
                        case Variable::TYPE_NATIVE_BOOL:
                            $boolVal = $this->context->helper->loadValue($arg);
                            $charPtr = $this->context->getTypeFromString('char*');
                            $trueBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_true');
                            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_done');
                            $this->context->builder->branchIf($boolVal, $trueBlock, $doneBlock);
                            $this->context->builder->positionAtEnd($trueBlock);
                            $this->context->builder->call(
                                $this->context->lookupFunction('__phpc_ob_echo_cstr'),
                                $this->context->builder->pointerCast(
                                    $this->context->constantFromString('1'),
                                    $charPtr
                                )
                            );
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($doneBlock);
                            break;

                        case Variable::TYPE_HASHTABLE:
                            JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                            break;
                        case Variable::TYPE_OBJECT:
                            JIT\ValueEchoHelper::echoLiteral($this->context, 'Object');
                            break;

                        default:
                            if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
                                JIT\ValueEchoHelper::echoLiteral($this->context, 'Array');
                                break;
                            }
                            if (Variable::KIND_VARIABLE === $arg->kind
                                && '__value__' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                JIT\ValueEchoHelper::echo(
                                    $this->context,
                                    JIT\JitValueBox::pointer($this->context, $arg->value)
                                );
                                break;
                            }
                            if (Variable::KIND_VALUE === $arg->kind
                                && '__value__*' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                JIT\ValueEchoHelper::echo($this->context, $arg->value);
                                break;
                            }
                            throw new \LogicException("Echo for type $arg->type not implemented");
                    }
                    if ($op->type === OpCode::TYPE_PRINT) {
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $this->context->constantFromInteger(1))
                        );
                    }
                    break;
                case OpCode::TYPE_EXIT:
                    if (null === $op->arg2) {
                        if (JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType) {
                            JIT\Builtin\PendingHeaders::emitFlushForStandalone($this->context);
                        }
                        $i32 = $this->context->getTypeFromString('int32');
                        $this->context->builder->call(
                            $this->context->lookupFunction('exit'),
                            $i32->constInt(0, false)
                        );
                        break;
                    }
                    $exitArg = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    JIT\Builtin\ScriptExit::emit($this->context, $exitArg);
                    break;
                case OpCode::TYPE_POW:
                    $pow = new \PHPCompiler\ext\standard\pow();
                    $powResult = $pow->call(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        $this->context->getVariableFromOp($block->getOperand($op->arg3))
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $powResult);
                    break;
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $this->assignOperand(
                        $this->operandAt($block, $op->arg1, opcode_type_name($op->type).' result'),
                        $this->compileBinaryOp(
                            $op,
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, opcode_type_name($op->type).' left')),
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, opcode_type_name($op->type).' right'))
                        )
                    );
                    break;
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_SPACESHIP:
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $this->assignOperand(
                        $this->operandAt($block, $op->arg1, opcode_type_name($op->type).' result'),
                        $this->compileBinaryOp(
                            $op,
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, opcode_type_name($op->type).' left')),
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg3, opcode_type_name($op->type).' right'))
                        )
                    );
                    break;
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_BITWISE_NOT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->helper->unaryOp(
                            $op,
                            $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        )
                    );
                    break;
                case OpCode::TYPE_CASE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $switchVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'switch value'));
                    $caseVar = $this->context->getVariableFromOp($this->operandAt($block, $op->arg2, 'switch case'));
                    $equalOp = new OpCode(OpCode::TYPE_EQUAL);
                    $matchVar = $this->context->helper->binaryOp($equalOp, $switchVar, $caseVar);
                    $match = $this->context->castToBool(
                        $this->context->helper->loadValue($matchVar)
                    );
                    $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    $caseEntry = $this->context->scope->blockStorage[$op->block1];
                    $nextBb = JIT\BasicBlockHelper::append($this->context, 'switch_next_case');
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($match, $caseEntry, $nextBb);
                    $builder->positionAtEnd($nextBb);
                    break;
                case OpCode::TYPE_JUMP:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    $targetEntry = $this->context->scope->blockStorage[$op->block1];
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Use the merge block itself (not getInsertBlock — callee may be cached) (#846, #784).
                        $this->context->inlineIncludeExitBlock = $targetEntry;
                    }
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branch($targetEntry);
                    return $origBasicBlock;
                case OpCode::TYPE_COALESCE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $coalesceResult = $block->getOperand($op->arg1);
                    $this->context->coalesceAssignTargets[$coalesceResult] = true;
                    $condition = $this->context->castToBool(
                        $this->context->helper->loadValue($this->context->getVariableFromOp($block->getOperand($op->arg2)))
                    );
                    // Branch from the block that defined $condition (e.g. sg_sk_done after $_SERVER['key']).
                    // Repositioning to $branchBlock caused invalid LLVM when ?? left uses multi-block reads (#866).
                    $coalesceTestBlock = $builder->getInsertBlock();
                    $leftTail = JIT\CoalesceHelper::compileBranch($this, $func, $op->block1);
                    $rightTail = JIT\CoalesceHelper::compileBranch($this, $func, $op->block2);
                    // Both branches compile; right-side literal metadata must not fold builtins (#764).
                    if ($this->context->hasVariableOp($coalesceResult)) {
                        $coalesceVar = $this->context->getVariableFromOp($coalesceResult);
                        $coalesceVar->compileTimeString = null;
                        $coalesceVar->compileTimeConstantName = null;
                    }
                    $leftEntry = $this->context->scope->blockStorage[$op->block1];
                    $rightEntry = $this->context->scope->blockStorage[$op->block2];
                    $builder->positionAtEnd($coalesceTestBlock);
                    // Do not free php-cfg "dead" operands here; ?? temps are used on branch/merge blocks (#99).
                    $builder->branchIf($condition, $leftEntry, $rightEntry);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'coalesce_merge');
                        $builder->positionAtEnd($leftTail);
                        if (null === $leftTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($rightTail);
                        if (null === $rightTail->getTerminator()) {
                            $builder->branch($mergeBb);
                        }
                        $builder->positionAtEnd($mergeBb);
                        if ($this->context->inlineIncludeDepth > 0) {
                            JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                        }
                        $merged = $this->compileBlockInternal($func, $op->block3, null, $mergeBb, ...$args);
                        unset($this->context->coalesceAssignTargets[$coalesceResult]);
                        if ($this->context->inlineIncludeDepth > 0) {
                            // Do not set inlineIncludeExitBlock to the ?? merge block (#866, #784).
                            break;
                        }

                        return $merged;
                    }
                    unset($this->context->coalesceAssignTargets[$coalesceResult]);
                    if ($this->context->inlineIncludeDepth > 0) {
                        // Two-branch ?? without merge: continue in the including TU (#866).
                        break;
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_NULLSAFE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $receiver = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $valuePtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $receiver);
                    $typeByte = $this->context->builder->load(
                        $this->context->builder->structGep(
                            $valuePtr,
                            $this->context->structFieldMap['__value__']['type']
                        )
                    );
                    $i8 = $this->context->getTypeFromString('int8');
                    $isNull = $this->context->builder->icmp(
                        \PHPLLVM\Builder::INT_EQ,
                        $typeByte,
                        $i8->constInt(JIT\Variable::TYPE_NULL, false)
                    );
                    $nullBb = JIT\NullsafeHelper::compileBranch($this, $func, $op->block1);
                    $fetchBb = JIT\NullsafeHelper::compileBranch($this, $func, $op->block2);
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($isNull, $nullBb, $fetchBb);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'nullsafe_merge');
                        $builder->positionAtEnd($nullBb);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($fetchBb);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($mergeBb);

                        return $this->compileBlockInternal($func, $op->block3, null, $mergeBb, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_JUMPIF:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->maybeRefreshIncludeBindingsBeforeUse();
                    $condition = $this->context->castToBool(
                        $this->context->helper->loadValue(
                            $this->context->getVariableFromOp($this->operandAt($block, $op->arg1, 'branch condition'))
                        )
                    );
                    // If-branch JUMP may compile a shared merge RETURN_VOID before the else/elseif arm
                    // runs; do not let inlineIncludeExitBlock leak across arms (#784, #846, #764).
                    $savedIncludeExit = null;
                    $exitAfterIfBranch = null;
                    if ($this->context->inlineIncludeDepth > 0) {
                        $savedIncludeExit = $this->context->inlineIncludeExitBlock;
                        $this->context->inlineIncludeExitBlock = null;
                    }
                    $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    if ($this->context->inlineIncludeDepth > 0) {
                        $exitAfterIfBranch = $this->context->inlineIncludeExitBlock;
                        $this->context->inlineIncludeExitBlock = null;
                    }
                    $this->compileBlockInternal($func, $op->block2, null, null, ...$args);
                    if ($this->context->inlineIncludeDepth > 0) {
                        $this->context->inlineIncludeExitBlock = $exitAfterIfBranch
                            ?? $this->context->inlineIncludeExitBlock
                            ?? $savedIncludeExit;
                    }
                    $ifEntry = $this->context->scope->blockStorage[$op->block1];
                    $elseEntry = $this->context->scope->blockStorage[$op->block2];
                    $builder->positionAtEnd($branchBlock);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $branchBlock, $block);
                    }
                    $builder->branchIf($condition, $ifEntry, $elseEntry);
                    return $origBasicBlock;
                case OpCode::TYPE_TRY:
                    JIT\TryCatchHelper::beginTry($this, $func, $this->context, $block, $op, $i, $args);

                    return $origBasicBlock;
                case OpCode::TYPE_CATCH:
                    if ([] !== $this->context->tryCatch->handlerStack) {
                        break;
                    }
                    if (null !== $op->block1) {
                        $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_FINALLY:
                    if ([] !== $this->context->tryCatch->handlerStack) {
                        break;
                    }
                    if (null !== $op->block1) {
                        $this->compileBlockInternal($func, $op->block1, null, null, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_THROW:
                    JIT\TryCatchHelper::emitThrow($this, $this->context, $func, $block, $op);

                    return $origBasicBlock;
                case OpCode::TYPE_RETURN_VOID:
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    $this->markJitThisConstructedIfLeavingConstruct($block);
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $returnBlock, $block);
                    }
                    if (0 === $this->context->inlineIncludeDepth) {
                        if (
                            !$this->isVoidLlvmFunction($func)
                            && null !== $block->func
                            && 'void' !== ($expectedReturn = $this->cfgFunctionReturnCallbackType($block->func))
                        ) {
                            $this->context->builder->returnValue(
                                $this->defaultLlvmReturnValueForCallbackType($expectedReturn, $func)
                            );
                        } else {
                            $this->context->builder->returnVoid();
                        }
                    } else {
                        $this->context->inlineIncludeExitBlock = $returnBlock;
                    }

                    return $this->context->inlineIncludeDepth > 0
                        ? $returnBlock
                        : $origBasicBlock;
                case OpCode::TYPE_RETURN:
                    $return = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    $this->markJitThisConstructedIfLeavingConstruct($block);
                    if ($this->context->inlineIncludeDepth > 0) {
                        if ([] !== $this->context->inlineIncludeReturnOperands) {
                            $holderOp = $this->context->inlineIncludeReturnOperands[
                                array_key_last($this->context->inlineIncludeReturnOperands)
                            ];
                            $return->addref();
                            $this->assignOperand($holderOp, $return, true);
                        }
                        $this->context->inlineIncludeExitBlock = $returnBlock;

                        return $returnBlock;
                    }
                    if ($this->shouldFreeDeadVariablesBeforeBranch()) {
                        $this->context->freeDeadVariables($func, $returnBlock, $block);
                    }
                    if ($this->isVoidLlvmFunction($func)) {
                        $this->context->builder->returnVoid();
                    } else {
                        $return->addref();
                        $retval = $this->context->helper->loadValue($return);
                        $expected = $this->cfgFunctionReturnCallbackType($block->func);
                        if (null === $expected && null !== $this->context->activeFunction) {
                            $expected = $this->context->functionReturnType[strtolower($this->context->activeFunction)] ?? null;
                        }
                        $retval = $this->coerceReturnValue($return, $retval, $expected);
                        $this->context->builder->returnValue($retval);
                    }
    
                    return $origBasicBlock;
                case OpCode::TYPE_FUNCDEF:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->compileBlock($op->block1, $nameOp->value);
                    break;
                case OpCode::TYPE_CLOSURE:
                    // Bootstrap stub: closures are not executable yet; represent as null.
                    $nullVar = new Variable(
                        $this->context,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $this->context->getTypeFromString('__value__*')->constNull()
                    );
                    $nullVar->isNullConstant = true;
                    $this->assignOperandValue($block->getOperand($op->arg1), $nullVar);
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $nameOp = $block->getOperand($op->arg1);
                    if ($nameOp instanceof Operand\Literal) {
                        $lcname = strtolower($nameOp->value);
                        $this->context->scope->toCall = $this->context->resolveFunctionProxy($lcname);
                    } else {
                        if (null !== $nameOp->type && Type::TYPE_OBJECT === $nameOp->type->type) {
                            $this->initJitMethodCall($block, $nameOp, '__invoke');
                            break;
                        }
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $nameSlot = $block->slotForOperand($nameOp);
                        if (null !== $nameSlot) {
                            $this->foldCompileTimeStringFromSlot($block, $nameSlot, $nameVar);
                        }
                        if (null === $nameVar->compileTimeString) {
                            if ($this->shouldUseSelfHostJitStubs()) {
                                $this->context->scope->toCall = null;
                                $this->context->scope->args = [];
                                break;
                            }
                            $hints = array_values(array_unique(array_merge(
                                JIT\VariableFunctionCallHelper::hintedCalleeNames($block, $nameSlot),
                                JIT\VariableFunctionCallHelper::coalesceBranchLiteralHints($block),
                                JIT\VariableFunctionCallHelper::funDefNamesInCompilationUnit($block)
                            )));
                            $this->context->scope->toCall = new JIT\Call\RuntimeVariableFunction($nameVar, $hints);
                        } else {
                            $lcname = strtolower($nameVar->compileTimeString);
                            if (!$this->context->functionIsRegistered($lcname)) {
                                if (str_contains($nameVar->compileTimeString, '::')) {
                                    throw new \LogicException("Call to undefined static method {$nameVar->compileTimeString}()");
                                }
                                throw new \LogicException("Call to undefined function {$lcname}()");
                            }
                            $this->context->scope->toCall = $this->context->resolveFunctionProxy($lcname);
                        }
                    }
                    $this->context->scope->args = [];
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    $this->initJitStaticCall($block, $op->arg1, $op->arg2);
                    break;
                case OpCode::TYPE_ARG_SEND:
                    if ($this->context->inlineIncludeDepth > 0) {
                        JIT\IncludeHelper::refreshInlineIncludeBindings($this->context);
                    }
                    $sendValue = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    if (null !== $op->arg3) {
                        $this->context->scope->args[] = ['unpack' => $sendValue];
                    } else {
                        $this->context->scope->args[] = $sendValue;
                    }
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($this->context->scope->toCall)) {
                        // short circuit
                        break;
                    }
                    $callArgs = $this->prependImplicitThisForStaticInstanceCall(
                        $block,
                        $this->context->scope->toCall,
                        $this->finalizeJitCallArgs($this->context->scope->args)
                    );
                    if (null !== $block->func && '{main}' === $block->func->name) {
                        $toCall = $this->context->scope->toCall;
                        $label = get_class($toCall);
                        if ($toCall instanceof CoreFunc\Internal) {
                            $label .= ':'.$toCall->getName();
                        } elseif ($toCall instanceof JIT\Call\Native) {
                            $label .= ':'.$toCall->name;
                        }
                        JIT\Progress::noteFunction('{main}:call='.$label);
                    }
                    $prevStrict = $this->context->callerStrictTypes;
                    $this->context->callerStrictTypes = $block->strictTypes;
                    $this->context->scope->toCall->call($this->context, ...$callArgs);
                    $this->markNewObjectConstructedAfterCall($this->context->scope->toCall, $callArgs);
                    $this->context->callerStrictTypes = $prevStrict;
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    $callArgs = $this->prependImplicitThisForStaticInstanceCall(
                        $block,
                        $this->context->scope->toCall,
                        $this->finalizeJitCallArgs($this->context->scope->args)
                    );
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'sprintf' === strtolower($this->context->scope->toCall->getName())
                        && 2 === count($callArgs)
                        && (
                            Variable::TYPE_NATIVE_LONG === $callArgs[1]->type
                            || Variable::TYPE_VALUE === $callArgs[1]->type
                            || JIT\JitValueBox::isValueOperand($callArgs[1])
                        )
                    ) {
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            JIT\JitNativeString::coerce($this->context, $callArgs[1])
                        );
                        break;
                    }
                    $prevStrict = $this->context->callerStrictTypes;
                    $this->context->callerStrictTypes = $block->strictTypes;
                    $result = $this->context->scope->toCall->call($this->context, ...$callArgs);
                    $this->markNewObjectConstructedAfterCall($this->context->scope->toCall, $callArgs);
                    $this->context->callerStrictTypes = $prevStrict;
                    $this->assignOperandValue($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    if (!isset($block->constants[$op->arg2])) {
                        if ($this->shouldUseSelfHostJitStubs()) {
                            break;
                        }
                        throw new \LogicException('Global constant value must be a compile-time constant');
                    }
                    if (!$this->context->runtime->vmContext->defineConstant(
                        $nameOp->value,
                        $block->constants[$op->arg2]
                    )) {
                        // Spine may require bin/vm.php after tokenizer-compat shims (#2134).
                        if ($this->shouldUseSelfHostJitStubs()) {
                            break;
                        }
                        throw new \LogicException("Cannot redefine constant {$nameOp->value}");
                    }
                    break;
                case OpCode::TYPE_DECLARE_INTERFACE:
                    if ($this->shouldUseSelfHostJitStubs()) {
                        break;
                    }
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->type->object->declareClass($nameOp);
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->scope->className = strtolower($nameOp->value);
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_ENUM:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareEnum($nameOp);
                    $this->context->scope->className = strtolower($nameOp->value);
                    if (null !== $this->context->runtime->vmContext) {
                        $this->context->runtime->vmContext->enums[strtolower($nameOp->value)] = true;
                    }
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->context->pushScope();
                    $this->context->scope->classId = $this->context->type->object->declareClass($nameOp);
                    $this->context->scope->className = strtolower($nameOp->value);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $this->context->type->object->setClassReadonly(
                            $this->context->scope->classId,
                            (bool) $block->constants[$op->arg3]->toInt()
                        );
                    }
                    $parentOp = null;
                    if (null !== $op->arg2) {
                        $parentOp = $block->getOperand($op->arg2);
                        assert($parentOp instanceof Operand\Literal);
                        $this->context->type->object->setClassParentName($nameOp->value, $parentOp->value);
                    }
                    if ([] !== $op->attributeNames) {
                        $attrNames = [];
                        foreach ($op->attributeNames as $n) {
                            $attrNames[] = ltrim($n, '\\');
                        }
                        AttributeRegistry::emitRegisterClass(
                            $this->context,
                            strtolower(ltrim($nameOp->value, '\\')),
                            $attrNames
                        );
                    }
                    $this->compileClass($op->block1, $this->context->scope->classId);
                    if ($parentOp instanceof Operand\Literal) {
                        $this->context->type->object->inheritReadonlyFromParent(
                            $this->context->scope->classId,
                            $parentOp->value
                        );
                    }
                    $this->context->popScope();
                    break;
                case OpCode::TYPE_NEW:
                    $classOp = $block->getOperand($op->arg2);
                    if ($classOp instanceof Operand\Literal && 0 === strcasecmp($classOp->value, 'SplObjectStorage')) {
                        $classId = $this->context->type->object->lookup('SplObjectStorage');
                        $obj = new Variable(
                            $this->context,
                            Variable::TYPE_OBJECT,
                            Variable::KIND_VALUE,
                            $this->context->type->object->allocate($classId)
                        );
                        $ht = $this->context->type->object->splBackingHashtable($obj);
                        $this->assignOperand($block->getOperand($op->arg1), $ht, true);
                        $this->context->scope->toCall = null;
                        $this->context->scope->args = [];
                    } else {
                        $classId = $this->context->type->object->resolveClassId($classOp);
                        $resolvedName = $this->context->type->object->classNameForId($classId);
                        if (!$this->context->type->object->hasUserDeclaredClass($resolvedName)) {
                            \PHPCompiler\ext\standard\JitSplAutoload::dispatchLiteral(
                                $this->context,
                                $resolvedName
                            );
                        }
                        $obj = new Variable(
                            $this->context,
                            Variable::TYPE_OBJECT,
                            Variable::KIND_VALUE,
                            $this->context->type->object->allocate($classId)
                        );
                        $resultOp = $block->getOperand($op->arg1);
                        $this->assignOperand($resultOp, $obj, true);
                        if ($classOp instanceof Operand\Literal
                            && 0 === strcasecmp(ltrim($classOp->value, '\\'), 'ReflectionClass')
                        ) {
                            $this->context->scope->toCall = $this->context->resolveFunctionProxy('reflectionclass::__construct');
                            $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                        } elseif ($this->context->type->object->hasConstructor($classId)) {
                            $proxyName = strtolower($resolvedName).'::'.'__construct';
                            $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                            $this->context->scope->args = [$this->context->getVariableFromOp($resultOp)];
                        } else {
                            $this->context->type->object->markObjectConstructed(
                                $this->context->helper->loadValue($obj)
                            );
                            $this->context->scope->toCall = null;
                            $this->context->scope->args = [];
                        }
                    }
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $receiverOp = $block->getOperand($op->arg1);
                    $nameOp = $block->getOperand($op->arg2);
                    assert($nameOp instanceof Operand\Literal);
                    $this->initJitMethodCall($block, $receiverOp, $nameOp->value);
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                    $result = $block->getOperand($op->arg1);
                    $obj = $block->getOperand($op->arg2);
                    $name = $block->getOperand($op->arg3);
                    assert($obj->type->type === Type::TYPE_OBJECT);
                    $propName = $name instanceof Operand\Literal ? $name->value : null;
                    $declaringClass = $this->resolvePropertyDeclaringClass($obj, $block, $propName);
                    $receiver = $this->loadPropertyFetchReceiver($obj);
                    if ($name instanceof Operand\Literal) {
                        $fetched = $this->context->type->object->propertyFetch(
                            $receiver,
                            $declaringClass,
                            $name->value
                        );
                        $this->context->scope->variables[$result] = $fetched;
                        $this->applyExternalPropertyResultType($result, $declaringClass, $name->value);
                    } else {
                        $nameVar = $this->context->getVariableFromOp($name);
                        $this->context->scope->variables[$result] = $this->context->type->object->propertyFetchDynamic(
                            $receiver,
                            $declaringClass,
                            $nameVar
                        );
                    }
                    break;
                default:
                    throw new \LogicException("Unknown JIT opcode: ". opcode_type_name($op->type));
            }
        }

        $tail = $builder->getInsertBlock();
        if (
            0 === $this->context->inlineIncludeDepth
            && $this->isVoidLlvmFunction($func)
            && !$block->syntheticCfgBranch
            && null !== $block->func
            && null !== $tail
            && null === $tail->getTerminator()
        ) {
            $builder->positionAtEnd($tail);
            $this->context->freeDeadVariables($func, $tail, $block);
            $this->context->builder->returnVoid();
        }

        return $builder->getInsertBlock();
    }

    private function coerceReturnValue(Variable $return, PHPLLVM\Value $retval, ?string $expected): PHPLLVM\Value
    {
        if ('__value__*' === $expected) {
            if (Variable::TYPE_VALUE === $return->type) {
                return JIT\JitValueBox::valuePtrFromVariable($this->context, $return);
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__value__*')->constNull();
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return JIT\JitValueBox::pointer($this->context, $slot);
            }

            return $this->context->getTypeFromString('__value__*')->constNull();
        }
        if ('__value__' === $expected) {
            if (Variable::TYPE_VALUE === $return->type) {
                if (Variable::KIND_VARIABLE === $return->kind) {
                    return $this->context->builder->load($return->value);
                }
                if ('__value__*' === $this->context->getStringFromType($retval->typeOf())) {
                    return $this->context->builder->load($retval);
                }

                return $retval;
            }
            if (Variable::TYPE_NULL === $return->type) {
                return $this->loadNullValueStruct();
            }
            if (Variable::TYPE_STRING === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $owned = $this->context->builder->call(
                    $this->context->lookupFunction('__string__separate'),
                    $retval
                );
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeString'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $owned
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_OBJECT === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeObject'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_HASHTABLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_LONG === $return->type || Variable::TYPE_NATIVE_BOOL === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $long = Variable::TYPE_NATIVE_BOOL === $return->type
                    ? $this->context->builder->zExt($retval, $this->context->getTypeFromString('int64'))
                    : $retval;
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $long
                );

                return $this->context->builder->load($slot);
            }
            if (Variable::TYPE_NATIVE_DOUBLE === $return->type) {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble'),
                    JIT\JitValueBox::pointer($this->context, $slot),
                    $retval
                );

                return $this->context->builder->load($slot);
            }

            return $this->loadNullValueStruct();
        }
        if (null === $expected || Variable::TYPE_VALUE !== $return->type) {
            if ('__string__*' === $expected && Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__string__*')->constNull();
            }
            if ('__hashtable__*' === $expected && Variable::TYPE_NULL === $return->type) {
                return $this->context->getTypeFromString('__hashtable__*')->constNull();
            }
            if ('__string__*' === $expected && Variable::TYPE_VALUE === $return->type) {
                return $this->context->builder->call(
                    $this->context->lookupFunction('__value__readString'),
                    JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
                );
            }

            return $retval;
        }
        if ('__string__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readString'),
                JIT\JitValueBox::valuePtrFromVariable($this->context, $return)
            );
        }
        $valuePtr = Variable::KIND_VARIABLE === $return->kind
            ? JIT\JitValueBox::pointer($this->context, $return->value)
            : JIT\BasicBlockHelper::entryAlloca(
                $this->context,
                $this->context->getTypeFromString('__value__')
            );
        if (Variable::KIND_VALUE === $return->kind) {
            $this->context->builder->store($retval, $valuePtr);
        }
        if ('long long' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }
        if ('bool' === $expected) {
            return $this->context->builder->truncOrBitCast(
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                ),
                $this->context->getTypeFromString('int1')
            );
        }
        if ('__object__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }
        if ('__hashtable__*' === $expected) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readHashtable'),
                $valuePtr
            );
        }

        return $retval;
    }

    private function operandAt(Block $block, ?int $slot, string $context): Operand
    {
        if (null === $slot) {
            throw new \LogicException('Missing operand slot for '.$context);
        }

        return $block->getOperand($slot);
    }

    private function isVoidCfgFunction(Block $block): bool
    {
        return 'void' === $this->cfgFunctionReturnCallbackType($block->func);
    }

    private function isVoidLlvmFunction(PHPLLVM\Value $func): bool
    {
        $fnType = $func->typeOf();
        if (!$fnType instanceof \PHPLLVM\Type\Function_) {
            return false;
        }

        return \PHPLLVM\Type::KIND_VOID === $fnType->getReturnType()->getKind();
    }

    private function defaultLlvmReturnValue(PHPLLVM\Value $func): PHPLLVM\Value
    {
        if (null !== $this->context->activeFunction) {
            $expected = $this->context->functionReturnType[$this->context->activeFunction] ?? null;
            if (null !== $expected) {
                return $this->defaultLlvmReturnValueForCallbackType($expected, $func);
            }
        }
        $fnType = $func->typeOf();
        if (!$fnType instanceof \PHPLLVM\Type\Function_) {
            return $this->context->constantFromInteger(0);
        }
        $llvmReturn = $this->context->getStringFromType($fnType->getReturnType());
        if ('unknown' === $llvmReturn && \PHPLLVM\Type::KIND_STRUCT === $fnType->getReturnType()->getKind()) {
            $llvmReturn = '__value__';
        }

        return $this->defaultLlvmReturnValueForCallbackType($llvmReturn, $func);
    }

    private function emitSelfHostStubReturn(string $callbackType, PHPLLVM\Value $func, ?int $longReturn = null): void
    {
        if ('void' === $callbackType) {
            $this->context->builder->returnVoid();
            return;
        }
        $this->context->builder->returnValue(
            $this->defaultLlvmReturnValueForCallbackType($callbackType, $func, $longReturn)
        );
    }

    private function defaultLlvmReturnValueForCallbackType(
        string $callbackType,
        PHPLLVM\Value $func,
        ?int $longReturn = null
    ): PHPLLVM\Value {
        switch ($callbackType) {
            case 'long long':
            case 'int64':
                return $this->context->getTypeFromString('int64')->constInt($longReturn ?? 0, false);
            case 'bool':
            case 'int1':
                return $this->context->getTypeFromString('bool')->constInt(0, false);
            case '__string__*':
                return $this->context->getTypeFromString('__string__*')->constNull();
            case '__object__*':
                return $this->context->getTypeFromString('__object__*')->constNull();
            case '__hashtable__*':
                return $this->context->getTypeFromString('__hashtable__*')->constNull();
            case '__value__*':
                return $this->context->getTypeFromString('__value__*')->constNull();
            case '__value__':
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull'),
                    JIT\JitValueBox::pointer($this->context, $slot)
                );
                return $this->context->builder->load($slot);
            default:
                $fnType = $func->typeOf();
                if ($fnType instanceof \PHPLLVM\Type\Function_) {
                    $returnType = $fnType->getReturnType();
                    if ($this->isValueStructLlvmType($returnType)) {
                        return $this->loadNullValueStruct();
                    }
                    if (\PHPLLVM\Type::KIND_POINTER === $returnType->getKind()) {
                        return $returnType->constNull();
                    }
                    if (\PHPLLVM\Type::KIND_INTEGER === $returnType->getKind()) {
                        return $returnType->constInt(0, false);
                    }
                }
                return $this->context->constantFromInteger(0);
        }
    }

    private function loadNullValueStruct(): PHPLLVM\Value
    {
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::pointer($this->context, $slot)
        );

        return $this->context->builder->load($slot);
    }

    private function isValueStructLlvmType(PHPLLVM\Type $type): bool
    {
        return $type->toString() === $this->context->getTypeFromString('__value__')->toString();
    }

    private function assignOperandsUsedByLiteralInclude(Block $block, OpCode $op): bool
    {
        if ([] === $block->literalIncludePaths) {
            return false;
        }
        foreach ($block->literalIncludePaths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $code = file_get_contents($path);
            if (false === $code || '' === $code) {
                continue;
            }
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $name = JIT\OperandName::resolve($block->getOperand($slotIdx));
                if (null === $name || '' === $name) {
                    continue;
                }
                if (preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolvePropertyDeclaringClass(Operand $obj, Block $block, ?string $propName): string
    {
        $declaringClass = $obj->type->userType ?? null;
        if (null === $declaringClass || '' === $declaringClass) {
            $operandName = strtolower(JIT\OperandName::resolve($obj) ?? '');
            if ('script' === $operandName) {
                $declaringClass = 'PHPCfg\\Script';
            } elseif (in_array($operandName, ['main', 'func'], true)) {
                $declaringClass = 'PHPCfg\\Func';
            } elseif (in_array($operandName, ['cfg', 'block'], true)) {
                $declaringClass = 'PHPCfg\\Block';
            }
        }
        if ((null === $declaringClass || '' === $declaringClass) && null !== $propName) {
            $declaringClass = $this->externalPropertyDeclaringClassFallback(
                $this->context->scope->className,
                $propName
            );
        }
        if (null === $declaringClass && null !== $block->func && null !== $block->func->class) {
            $declaringClass = $block->func->class->value;
        }
        if (null === $declaringClass || '' === $declaringClass) {
            $declaringClass = $this->context->scope->className !== ''
                ? $this->context->scope->className
                : 'object';
        }

        return $declaringClass;
    }

    private function externalPropertyDeclaringClassFallback(string $scopeClass, string $propName): ?string
    {
        if (!str_starts_with(strtolower($scopeClass), 'phpcompiler\\')) {
            return null;
        }
        $lcProp = strtolower($propName);
        if ('main' === $lcProp) {
            return 'PHPCfg\\Script';
        }
        if ('cfg' === $lcProp) {
            return 'PHPCfg\\Func';
        }

        return null;
    }

    private function applyExternalPropertyResultType(Operand $result, string $declaringClass, string $propName): void
    {
        $userType = $this->externalPropertyResultUserType($declaringClass, $propName);
        if (null === $userType) {
            return;
        }
        $result->type = Type::object($userType);
    }

    private function externalPropertyResultUserType(string $class, string $name): ?string
    {
        $lcClass = strtolower(str_replace('/', '\\', ltrim($class, '\\')));
        $lcName = strtolower($name);
        if (str_starts_with($lcClass, 'phpcfg\\script') && 'main' === $lcName) {
            return 'PHPCfg\\Func';
        }
        if (str_starts_with($lcClass, 'phpcfg\\func') && 'cfg' === $lcName) {
            return 'PHPCfg\\Block';
        }

        return null;
    }

    private function rawTypeFromCfgParam(\PHPCfg\Op\Expr\Param $param): Type
    {
        $declared = $this->declaredTypeFromCfgParam($param);
        if ($param->declaredType instanceof Op\Type\Literal
            && 'mixed' === strtolower($param->declaredType->name)
        ) {
            return Type::mixed();
        }
        if (null !== $declared && Type::TYPE_UNION === $declared->type) {
            return $declared;
        }
        if (null !== $param->result->type && Type::TYPE_NULL !== $param->result->type->type) {
            return $param->result->type;
        }
        if (null !== $declared) {
            return $declared;
        }
        if (null !== $param->result->type) {
            return $param->result->type;
        }

        return Type::mixed();
    }

    private function rawTypeFromCfgReturn(?\PHPCfg\Op\Type $returnType): ?Type
    {
        if (null === $returnType) {
            return null;
        }
        if ($returnType instanceof Op\Type\Literal) {
            return Type::fromDecl($returnType->name);
        }
        if ($returnType instanceof Op\Type\Reference && null !== $returnType->declaration) {
            $inner = $returnType->declaration;
            if ($inner instanceof \PHPCfg\Operand\Literal) {
                return Type::fromDecl($inner->value);
            }
            if ($inner instanceof Op\Type\Literal) {
                return Type::fromDecl($inner->name);
            }
            try {
                return Type::fromTypeDecl($inner);
            } catch (\LogicException) {
                return null;
            }
        }
        try {
            return Type::fromTypeDecl($returnType);
        } catch (\LogicException) {
            return null;
        }
    }

    private function typeIncludesNull(Type $type): bool
    {
        if (Type::TYPE_NULL === $type->type) {
            return true;
        }
        if (Type::TYPE_UNION === $type->type) {
            foreach ($type->subTypes ?? [] as $sub) {
                if ($this->typeIncludesNull($sub)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function callbackTypeFromPhptype(Type $type): ?string
    {
        $allowsNull = $this->typeIncludesNull($type);
        $type = $this->context->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                $callback = 'long long';
                break;
            case Type::TYPE_BOOLEAN:
                $callback = 'bool';
                break;
            case Type::TYPE_STRING:
                $callback = '__string__*';
                break;
            case Type::TYPE_OBJECT:
                $callback = '__object__*';
                break;
            case Type::TYPE_ARRAY:
                $callback = '__hashtable__*';
                break;
            case Type::TYPE_NULL:
                $callback = '__value__';
                break;
            default:
                $callback = null;
                break;
        }
        if ($allowsNull && null !== $callback && '__value__' !== $callback && '__object__*' !== $callback) {
            return '__value__*';
        }

        return $callback;
    }

    /**
     * LLVM return type tag for a CFG function (must match compileBlock() signature lowering).
     */
    private function cfgFunctionReturnCallbackType(?\PHPCfg\Func $cfgFunc): ?string
    {
        if (null === $cfgFunc) {
            return null;
        }
        if ('__construct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Never_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Nullable) {
            $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType->subtype);
            if (null !== $rawReturn) {
                $callback = $this->callbackTypeFromPhptype($rawReturn);
                if (null !== $callback) {
                    return $callback;
                }
            }
        }
        $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType);
        if (null !== $rawReturn) {
            $callback = $this->callbackTypeFromPhptype($rawReturn);
            if (null !== $callback) {
                return $callback;
            }
        }
        if ($cfgFunc->returnType instanceof Op\Type\Literal) {
            switch ($cfgFunc->returnType->name) {
                case 'void':
                case 'never':
                    return 'void';
                case 'int':
                    return 'long long';
                case 'string':
                    return '__string__*';
                case 'bool':
                    return 'bool';
                case 'object':
                    return '__object__*';
                case 'array':
                    return '__hashtable__*';
                default:
                    return '__value__';
            }
        }

        return '__value__';
    }

    /** Class const / property default lowering only; values live in $block->constants (self-host bundle). */
    private function isSelfHostClassBodyEpilogueOpcode(int $type): bool
    {
        return OpCode::TYPE_UNARY_MINUS === $type
            || OpCode::TYPE_PLUS === $type
            || OpCode::TYPE_MUL === $type
            || OpCode::TYPE_BITWISE_OR === $type
            || OpCode::TYPE_BITWISE_AND === $type
            || OpCode::TYPE_BITWISE_XOR === $type
            || OpCode::TYPE_SHIFT_LEFT === $type
            || OpCode::TYPE_SHIFT_RIGHT === $type;
    }

    /** Bootstrap fixture: compile only isSuperglobalName from bundled Web\\Superglobals (#816). */
    private function isBundledSuperglobalsClass(int $classId): bool
    {
        $name = strtolower($this->context->scope->className ?? '');

        return 'phpcompiler\\web\\superglobals' === $name || 'superglobals' === $name;
    }

    private function compileClass(?Block $block, int $classId) {
        if ($block === null) {
            return;
        }
        foreach ($block->opCodes as $op) {
            switch ($op->type) {
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $className = $this->context->scope->className ?? '';
                    $declaredJitType = Variable::getTypeFromType($block->getOperand($op->arg3)->type);
                    if (
                        Variable::TYPE_NATIVE_LONG !== $declaredJitType
                        && Variable::TYPE_STRING !== $declaredJitType
                        && Variable::TYPE_NATIVE_BOOL !== $declaredJitType
                        && Variable::TYPE_NATIVE_DOUBLE !== $declaredJitType
                    ) {
                        $declaredJitType = $this->context->type->object->externalPropertyJitType(
                            $className,
                            $name->value
                        );
                    }
                    $default = (null !== $op->arg2 && isset($block->constants[$op->arg2]))
                        ? $block->constants[$op->arg2]
                        : null;
                    $this->context->type->object->defineStaticProperty(
                        $classId,
                        $name->value,
                        $declaredJitType,
                        $default
                    );
                    break;
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $className = $this->context->scope->className ?? '';
                    $declaredJitType = Variable::getTypeFromType($block->getOperand($op->arg3)->type);
                    if (Variable::TYPE_HASHTABLE === $declaredJitType || Variable::TYPE_STRING === $declaredJitType) {
                        $jitType = $declaredJitType;
                        if (Variable::TYPE_HASHTABLE === $declaredJitType) {
                            $lcClass = strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                            if (
                                !str_starts_with($lcClass, 'phpcfg\\')
                                && !str_starts_with($lcClass, 'phpcompiler\\')
                            ) {
                                $jitType = Variable::TYPE_VALUE;
                            }
                        }
                    } else {
                        $jitType = $this->context->type->object->externalPropertyJitType(
                            $className,
                            $name->value
                        );
                    }
                    $this->context->type->object->defineProperty($classId, $name->value, $jitType);
                    if (null !== $op->arg2 && isset($block->constants[$op->arg2])) {
                        $this->context->type->object->definePropertyDefault(
                            $classId,
                            $name->value,
                            $block->constants[$op->arg2]
                        );
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                case OpCode::TYPE_CLASS_CONST_FETCH:
                case OpCode::TYPE_INIT_ARRAY:
                    // Default property values are initialized in __object__ allocation.
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    $methodLc = strtolower($name->value);
                    if ([] !== $op->attributeNames && '' !== $this->context->scope->className) {
                        $attrNames = [];
                        foreach ($op->attributeNames as $n) {
                            $attrNames[] = ltrim($n, '\\');
                        }
                        AttributeRegistry::emitRegisterMethod(
                            $this->context,
                            strtolower(ltrim($this->context->scope->className, '\\')),
                            $methodLc,
                            $attrNames
                        );
                    }
                    if (($this->isBundledSuperglobalsClass($classId) || $this->shouldSkipExternalClassBodyLowering($classId))
                        && 'issuperglobalname' !== $methodLc
                    ) {
                        break;
                    }
                    $visFlags = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $visFlags = MethodVisibility::mask($block->constants[$op->arg3]->toInt());
                    }
                    $this->context->type->object->defineMethodVisibility(
                        $this->context->scope->classId,
                        $methodLc,
                        $visFlags
                    );
                    $methodBlock = $op->block1;
                    $className = null !== $methodBlock && null !== $methodBlock->func && null !== $methodBlock->func->class
                        ? strtolower($methodBlock->func->class->value)
                        : $this->context->scope->className;
                    $funcName = $className.'::'.$methodLc;
                    if (null !== $methodBlock) {
                        if ('__construct' === $methodLc) {
                            $this->context->type->object->markHasConstructor($this->context->scope->classId);
                        }
                        $this->compileBlock($methodBlock, $funcName);
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    if (!isset($block->constants[$op->arg2])) {
                        if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                            break;
                        }
                        throw new \LogicException('Class constant value must be a compile-time constant');
                    }
                    $this->context->type->object->defineClassConst(
                        $classId,
                        $name->value,
                        $block->constants[$op->arg2]
                    );
                    break;
                default:
                    if ($this->shouldSkipExternalClassBodyLowering($classId)) {
                        break;
                    }
                    throw new \LogicException('Other class body types are not jittable for now');
            }
            
        }
    }

    public function assignIncludeResult(Operand $result): void
    {
        if ([] !== $this->context->inlineIncludeReturnOperands) {
            $holderOp = $this->context->inlineIncludeReturnOperands[
                array_key_last($this->context->inlineIncludeReturnOperands)
            ];
            $this->assignOperand($result, $this->context->getVariableFromOp($holderOp), true);

            return;
        }
        $this->assignOperand(
            $result,
            new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $this->context->constantFromInteger(1)
            )
        );
    }

    public function assignOperandForced(Operand $result, Variable $value): void
    {
        $this->assignOperand($result, $value, true);
    }

    private function assignOperand(Operand $resultOp, Variable $value, bool $force = false): void {
        if (
            !$force
            && empty($resultOp->usages)
            && !$this->context->scope->variables->contains($resultOp)
        ) {
            return;
        }
        if (!$this->context->hasVariableOp($resultOp)) {
            // it's a kind!
            $this->context->makeVariableFromValueOp($this->context->helper->loadValue($value), $resultOp);
            return;
        }
        $result = $this->context->getVariableFromOp($resultOp);
        if ($result === $value) {
            return;
        }
        if (
            $force
            && Variable::KIND_VALUE === $result->kind
            && Variable::TYPE_STRING !== $result->type
        ) {
            // ?? left branch fetch binds a superglobal lvalue; force-assign needs a stack slot (#866).
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                )
            );
            $result = $this->context->getVariableFromOp($resultOp);
        }
        if (
            !$force
            && $resultOp instanceof \PHPCfg\Operand\Temporary
            && Variable::KIND_VALUE === $result->kind
            && Variable::TYPE_STRING !== $result->type
        ) {
            // Temporaries can start life as rvalues; promote to a boxed stack slot on first assignment.
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                )
            );
            $result = $this->context->getVariableFromOp($resultOp);
        }
        if (null !== $result->objectPropertySlot) {
            if (null === $result->objectPropertyType) {
                throw new \LogicException('objectPropertySlot requires objectPropertyType');
            }
            JIT\ReadonlyClassGuard::emitBeforePropertyStore(
                $this->context,
                $result,
                $this->context->jitEnclosingBlock
            );
            $this->context->type->object->propertyStore(
                $result->objectPropertySlot,
                $value,
                $result->objectPropertyType
            );

            return;
        }
        if (null !== $result->staticPropertyGlobal) {
            if (null === $result->staticPropertyType) {
                throw new \LogicException('staticPropertyGlobal requires staticPropertyType');
            }
            $this->context->type->object->staticPropertyStore(
                $result->staticPropertyGlobal,
                $value,
                $result->staticPropertyType
            );

            return;
        }
        if (null !== $result->writableHt && null !== $result->writableValueBoxKey) {
            JIT\HashTableHelper::setValueBoxKey(
                $this->context,
                $result->writableHt,
                $result->writableValueBoxKey,
                $value
            );

            return;
        }
        if ($result->kind === Variable::KIND_VALUE && $result->type === Variable::TYPE_STRING) {
            JIT\StringOffsetHelper::dimAssign($this->context, $result->value, $value);

            return;
        }
        if ($result->kind !== Variable::KIND_VARIABLE) {
            throw new \LogicException("Cannot assign to a value");
        }
        if ($value->type === $result->type) {
            if (!$result->includeBinding) {
                $result->free();
            }
            if ($value->type & Variable::IS_NATIVE_ARRAY || Variable::TYPE_HASHTABLE === $value->type) {
                $result->nextFreeElement = $value->nextFreeElement;
            }
            if (Variable::TYPE_VALUE === $value->type) {
                $destLlvm = $result->value->typeOf();
                $destTy = $this->context->getStringFromType($destLlvm);
                if ('__value__' === $destTy || '__value__*' === $destTy) {
                    $destPointsAtStruct = '__value__' === $destTy;
                    if (
                        '__value__*' === $destTy
                        && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                        && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                    ) {
                        $destPointsAtStruct = true;
                    }
                    if ($destPointsAtStruct) {
                        JIT\JitValueBox::copyFromPointer(
                            $this->context,
                            $result->value,
                            $this->valueBoxPointer($value)
                        );
                    } else {
                        $this->context->builder->store(
                            $this->valueBoxPointer($value),
                            $result->value
                        );
                    }
                    $this->copyObjectPropertyBacking($result, $value);
                    if (null === $result->objectPropertySlot) {
                        $result->addref();
                    }
                    $this->copyValueBoxJitFlags($result, $value, $force);

                    return;
                }
            }
            $toStore = $this->context->helper->loadValue($value);
            $this->context->builder->store(
                $toStore,
                $result->value
            );
            $this->copyObjectPropertyBacking($result, $value);
            if (null === $result->objectPropertySlot) {
                $result->addref();
            }
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $this->syncCompileTimeString($result, $value, $force);

            return;
        } elseif ($result->type === Variable::TYPE_VALUE) {
            // wrap
            $valueRef = $result->value;
            $valueFrom = $value->value;
            if ($value->type & Variable::IS_NATIVE_ARRAY) {
                $ht = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    $valueRef,
                    $ht
                );
                $this->context->refcount->addref($ht);
                $result->valueBoxHashtable = true;

                return;
            }
            switch ($value->type) {
                case Variable::TYPE_NULL:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull') , 
                    $valueRef
                    
                );
                    $result->isNullConstant = $value->isNullConstant;
    
                    return;
                case Variable::TYPE_NATIVE_LONG:
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyLong'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $this->context->helper->loadValue($value)
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyLong'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $this->context->helper->loadValue($value)
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong') , 
                    $valueRef
                    , $this->context->helper->loadValue($value)
                    
                );
    
                    return;
                case Variable::TYPE_NATIVE_DOUBLE:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble') , 
                    $valueRef
                    , $this->context->helper->loadValue($value)
                    
                );
    
                    return;
                case Variable::TYPE_NATIVE_BOOL:
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyBool'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $this->context->helper->loadValue($value)
                        );

                        return;
                    }
                    JIT\JitValueBox::writeBool(
                        $this->context,
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );

                    return;
                case Variable::TYPE_STRING:
                    $str = $this->context->helper->loadValue($value);
                    $owned = $this->context->builder->call(
                        $this->context->lookupFunction('__string__separate'),
                        $str
                    );
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyString'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $owned
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeString'),
                        $valueRef,
                        $owned
                    );
                    $this->syncCompileTimeString($result, $value, $force);

                    return;
                case Variable::TYPE_HASHTABLE:
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeHashtable'),
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );
                    $result->valueBoxHashtable = true;

                    return;
                case Variable::TYPE_OBJECT:
                    $objVal = $this->context->helper->loadValue($value);
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyObject'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $objVal
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeObject'),
                        $valueRef,
                        $objVal
                    );

                    return;
                case Variable::TYPE_VALUE:
                    JIT\JitValueBox::copyFromPointer(
                        $this->context,
                        $valueRef,
                        $this->valueBoxPointer($value)
                    );
                    $this->copyValueBoxJitFlags($result, $value, $force);

                    return;
                default:
                    if ($value->type & Variable::IS_NATIVE_ARRAY) {
                        $ht = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__writeHashtable'),
                            $valueRef,
                            $ht
                        );
                        $result->valueBoxHashtable = true;

                        return;
                    }
                    throw new \LogicException("Source type: {$value->type}");
            }
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_VALUE === $value->type) {
            $fp = $this->unboxValueToNativeDouble($value);
            $longVal = $this->context->builder->fpToSi(
                $fp,
                $this->context->getTypeFromString('int64')
            );
            $result->free();
            $this->context->builder->store($longVal, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $result->free();
            $fp = $this->context->helper->loadValue($value);
            $long = $this->context->builder->fpToSi($fp, $this->context->getTypeFromString('int64'));
            $this->context->builder->store($long, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_NATIVE_LONG === $value->type) {
            $result->free();
            $long = $this->context->helper->loadValue($value);
            $fp = $this->context->builder->siToFp($long, $this->context->getTypeFromString('double'));
            $this->context->builder->store($fp, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_VALUE === $value->type) {
            $fp = $this->unboxValueToNativeDouble($value);
            $result->free();
            $this->context->builder->store($fp, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_VALUE === $value->type) {
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $result->value,
                $this->valueBoxPointer($value)
            );
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $this->syncCompileTimeString($result, $value, $force);

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_VALUE === $value->type) {
            $ht = $this->context->builder->call(
                $this->context->lookupFunction('__value__readHashtable'),
                $this->valueBoxPointer($value)
            );
            $result->free();
            $this->context->builder->store($ht, $result->value);
            $this->copyObjectPropertyBacking($result, $value);
            if (null === $result->objectPropertySlot) {
                $result->addref();
            }

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_VALUE === $value->type) {
            // getenv() and similar builtins return string|false as __value__; keep the box
            // so strict comparisons against false use JitValueCompare (issue #848).
            $slot = JIT\JitValueBox::alloc($this->context);
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $slot,
                $this->valueBoxPointer($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();

            return;
        } elseif (Variable::TYPE_NATIVE_BOOL === $result->type && Variable::TYPE_VALUE === $value->type) {
            $boolVal = $this->context->castToBool($this->context->helper->loadValue($value));
            $result->free();
            $this->context->builder->store($boolVal, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_VALUE === $value->type) {
            $valuePtr = $this->valueBoxPointer($value);
            $map = $this->context->structFieldMap['__value__'];
            $typeByte = $this->context->builder->load(
                $this->context->builder->structGep($valuePtr, $map['type'])
            );
            $i8 = $this->context->getTypeFromString('int8');
            $isLong = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            );
            $isBool = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
            );
            $isStreamHandle = $this->context->builder->bitwiseOr($isLong, $isBool);
            $objectBlock = JIT\BasicBlockHelper::append($this->context, 'assign_object_from_value');
            $handleBlock = JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_from_value');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'assign_object_from_value_done');
            $this->context->builder->branchIf($isStreamHandle, $handleBlock, $objectBlock);
            $this->context->builder->positionAtEnd($objectBlock);
            $obj = $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                $valuePtr
            );
            $result->free();
            $this->context->builder->store($obj, $result->value);
            $result->addref();
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($handleBlock);
            $result->free();
            $slot = JIT\JitValueBox::alloc($this->context);
            $destPtr = JIT\JitValueBox::pointer($this->context, $slot);
            $longBlock = JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_long');
            $boolBlock = JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_bool');
            $this->context->builder->branchIf($isLong, $longBlock, $boolBlock);
            $this->context->builder->positionAtEnd($longBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                )
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($boolBlock);
            JIT\JitValueBox::writeBool(
                $this->context,
                $slot,
                $this->context->builder->truncOrBitCast(
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    ),
                    $this->context->getTypeFromString('int1')
                )
            );
            $this->context->builder->branch($doneBlock);
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();
            $this->context->builder->positionAtEnd($doneBlock);

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_HASHTABLE === $value->type) {
            $ht = $this->context->helper->loadValue($value);
            $result->free();
            $this->context->builder->store(
                $this->context->builder->pointerCast(
                    $ht,
                    $this->context->getTypeFromString('__object__*')
                ),
                $result->value
            );
            $result->addref();

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_OBJECT === $value->type) {
            if (null !== $result->writableHt && null !== $result->writableIndex) {
                JIT\HashTableHelper::setAtIndex(
                    $this->context,
                    $result->writableHt,
                    $result->writableIndex,
                    $value
                );

                return;
            }
            $obj = $this->context->helper->loadValue($value);
            $result->free();
            $this->context->builder->store(
                $this->context->builder->pointerCast(
                    $obj,
                    $this->context->getTypeFromString('__hashtable__*')
                ),
                $result->value
            );
            $result->addref();

            return;
        }
        throw new \LogicException("Cannot assign operands of different types (yet): {$value->type}, {$result->type}");
    }

    private function valueBoxPointer(Variable $value): PHPLLVM\Value
    {
        return JIT\JitValueBox::valuePtrFromVariable($this->context, $value);
    }

    private function unboxValueToNativeDouble(Variable $value): PHPLLVM\Value
    {
        $valuePtr = $this->valueBoxPointer($value);
        $map = $this->context->structFieldMap['__value__'];
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $this->context->getTypeFromString('int8');
        $doubleTy = $this->context->getTypeFromString('double');
        $isDouble = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isLong = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $readDouble = $this->context->builder->call(
            $this->context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $readLong = $this->context->builder->call(
            $this->context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $fromLong = $this->context->builder->siToFp($readLong, $doubleTy);

        return $this->context->builder->select(
            $isDouble,
            $readDouble,
            $this->context->builder->select($isLong, $fromLong, $doubleTy->constReal(0.0))
        );
    }

    private function assignOperandValue(Operand $result, PHPLLVM\Value $value): void {
        if (empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            $this->context->makeVariableFromValueOp($value, $result);

            return;
        }
        $dest = $this->context->getVariableFromOp($result);
        if ($dest->kind !== Variable::KIND_VARIABLE) {
            throw new \LogicException('Cannot assign to a value');
        }
        $valueTy = $this->context->getStringFromType($value->typeOf());
        $destTy = $this->context->getStringFromType($dest->value->typeOf());
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            if ('int1' === $valueTy || 'bool' === $valueTy) {
                $dest->free();
                $this->context->builder->store($value, $dest->value);
                $dest->addref();

                return;
            }
        }
        if (Variable::TYPE_NATIVE_LONG === $dest->type || Variable::TYPE_NATIVE_DOUBLE === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
        }
        if ('__string__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $dest->free();
            $isNullPtr = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $value,
                $value->typeOf()->constNull()
            );
            $nullBlock = JIT\BasicBlockHelper::append($this->context, 'assign_string_null_ptr');
            $copyBlock = JIT\BasicBlockHelper::append($this->context, 'assign_string_copy_ptr');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'assign_string_ptr_done');
            $this->context->builder->branchIf($isNullPtr, $nullBlock, $copyBlock);
            $this->context->builder->positionAtEnd($nullBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                JIT\JitValueBox::pointer($this->context, $dest->value)
            );
            $dest->isNullConstant = true;
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($copyBlock);
            $owned = $this->context->builder->call(
                $this->context->lookupFunction('__string__separate'),
                $value
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                JIT\JitValueBox::pointer($this->context, $dest->value),
                $owned
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($doneBlock);
            $dest->addref();

            return;
        }
        if ('__value__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $dest->free();
            $isNullPtr = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $value,
                $value->typeOf()->constNull()
            );
            $nullBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_null_ptr');
            $copyBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_copy_ptr');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_ptr_done');
            $this->context->builder->branchIf($isNullPtr, $nullBlock, $copyBlock);
            $this->context->builder->positionAtEnd($nullBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                JIT\JitValueBox::pointer($this->context, $dest->value)
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($copyBlock);
            JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $value);
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($doneBlock);
            $dest->addref();

            return;
        }
        $source = new Variable(
            $this->context,
            $this->jitTypeFromLlvmValue($value),
            Variable::KIND_VALUE,
            $value
        );
        if ($source->type === $dest->type) {
            $dest->free();
            if (Variable::TYPE_VALUE === $dest->type && ('__value__' === $destTy || '__value__*' === $destTy)) {
                $destLlvm = $dest->value->typeOf();
                $destPointsAtStruct = '__value__' === $destTy;
                if (
                    '__value__*' === $destTy
                    && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                    && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                ) {
                    $destPointsAtStruct = true;
                }
                if ('__value__' === $valueTy && $destPointsAtStruct) {
                    $this->context->builder->store($value, $dest->value);
                    $dest->addref();
                    $this->copyValueBoxJitFlags($dest, $source);

                    return;
                }
                $ptr = '__value__*' === $valueTy
                    ? $value
                    : $this->valueBoxPointer($source);
                if ($destPointsAtStruct) {
                    JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $ptr);
                } else {
                    $this->context->builder->store($ptr, $dest->value);
                }
                $dest->addref();
                $this->copyValueBoxJitFlags($dest, $source);

                return;
            }
            $toStore = $value;
            if ('__value__*' === $valueTy && '__value__' === $destTy) {
                $toStore = $this->context->builder->load($value);
            }
            $this->context->builder->store($toStore, $dest->value);
            $dest->addref();
            $this->copyValueBoxJitFlags($dest, $source);

            return;
        }
        $this->assignOperand($result, $source);
    }

    private function syncCompileTimeString(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeString) {
            $dest->compileTimeString = $src->compileTimeString;
        }
    }

    private function copyValueBoxJitFlags(Variable $dest, Variable $src, bool $force = false): void
    {
        if (Variable::TYPE_VALUE !== $dest->type || Variable::TYPE_VALUE !== $src->type) {
            return;
        }
        $dest->valueBoxHashtable = $src->valueBoxHashtable;
        $dest->isNullConstant = $src->isNullConstant;
        $this->syncCompileTimeString($dest, $src, $force);
    }

    /** Keep borrowed object-property hashtable metadata on locals ($cfg = $this->config, #848). */
    private function copyObjectPropertyBacking(Variable $dest, Variable $src): void
    {
        $dest->objectPropertySlot = $src->objectPropertySlot;
        $dest->objectPropertyType = $src->objectPropertyType;
        $dest->objectPropertyReceiver = $src->objectPropertyReceiver;
        $dest->objectPropertyName = $src->objectPropertyName;
        $dest->objectPropertyClassName = $src->objectPropertyClassName;
    }

    private function markJitThisConstructedIfLeavingConstruct(Block $block): void
    {
        if (!$this->isJitConstructFrame($block)) {
            return;
        }
        $thisVar = $this->resolveThisVariable($block);
        if (null === $thisVar || Variable::TYPE_OBJECT !== $thisVar->type) {
            return;
        }
        $this->context->type->object->markObjectConstructed(
            $this->context->helper->loadValue($thisVar)
        );
    }

    private function isJitConstructFrame(Block $block): bool
    {
        $func = $block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    /**
     * @param list<JIT\Variable|array{unpack: JIT\Variable}> $callArgs
     */
    private function markNewObjectConstructedAfterCall(?JIT\Call $toCall, array $callArgs): void
    {
        if (null === $toCall) {
            return;
        }
        if ($toCall instanceof JIT\Call\Native) {
            $name = strtolower($toCall->name);
        } elseif ($toCall instanceof JIT\Call\ExternalMethod) {
            $name = strtolower($toCall->proxyName);
        } else {
            return;
        }
        if (!str_ends_with($name, '::__construct')) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $first = $callArgs[0];
        if (is_array($first)) {
            $first = $first['unpack'] ?? null;
        }
        if (!$first instanceof JIT\Variable || Variable::TYPE_OBJECT !== $first->type) {
            return;
        }
        $this->context->type->object->markObjectConstructed(
            $this->context->helper->loadValue($first)
        );
    }

    private function jitTypeFromLlvmValue(PHPLLVM\Value $value): int
    {
        switch ($this->context->getStringFromType($value->typeOf())) {
            case 'double':
                return Variable::TYPE_NATIVE_DOUBLE;
            case 'int1':
            case 'bool':
                return Variable::TYPE_NATIVE_BOOL;
            case 'int64':
            case 'long long':
            case 'int32':
            case 'size_t':
            case 'unsigned int':
                return Variable::TYPE_NATIVE_LONG;
            case '__string__*':
                return Variable::TYPE_STRING;
            case '__object__*':
                return Variable::TYPE_OBJECT;
            case '__hashtable__*':
                return Variable::TYPE_HASHTABLE;
            case '__value__':
            case '__value__*':
                return Variable::TYPE_VALUE;
            default:
                throw new \LogicException(
                    'Cannot infer JIT variable type from LLVM type: '
                    .$this->context->getStringFromType($value->typeOf())
                );
        }
    }

    private function compileBinaryOp(OpCode $op, Variable $left, Variable $right): Variable
    {
        if (Variable::TYPE_VALUE === $left->type && Variable::TYPE_VALUE === $right->type) {
            switch ($op->type) {
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                    return $this->compileValueBoxedBitwiseOp($op->type, $left, $right);
            }
        }

        return $this->context->helper->binaryOp($op, $left, $right);
    }

    private function compileValueBoxedBitwiseOp(int $opcodeType, Variable $left, Variable $right): Variable
    {
        $leftPtr = Variable::KIND_VARIABLE === $left->kind
            ? $left->value
            : $this->context->helper->loadValue($left);
        $rightPtr = Variable::KIND_VARIABLE === $right->kind
            ? $right->value
            : $this->context->helper->loadValue($right);
        $readLong = $this->context->lookupFunction('__value__readLong');
        $leftLong = $this->context->builder->call($readLong, $leftPtr);
        $rightLong = $this->context->builder->call($readLong, $rightPtr);
        switch ($opcodeType) {
            case OpCode::TYPE_BITWISE_AND:
                $result = $this->context->builder->bitwiseAnd($leftLong, $rightLong);
                break;
            case OpCode::TYPE_BITWISE_OR:
                $result = $this->context->builder->bitwiseOr($leftLong, $rightLong);
                break;
            case OpCode::TYPE_BITWISE_XOR:
                $result = $this->context->builder->bitwiseXor($leftLong, $rightLong);
                break;
            default:
                throw new \LogicException('Unsupported boxed bitwise opcode: '.opcode_type_name($opcodeType));
        }

        return new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $result);
    }

    private function jitVariableArrayClassConstant(string $constName): ?Variable
    {
        switch (strtolower($constName)) {
            case 'native_type_map':
                return $this->jitVariableNativeTypeMapConstant();
            case 'type_map':
                return $this->jitVariableTypeMapConstant();
            default:
                return null;
        }
    }

    private function bumpNativeArrayNextFreeForExplicitIntKey(
        Variable $array,
        ?int $keyArg,
        Block $block
    ): void {
        if (null === $keyArg || 0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return;
        }
        $keyOp = $block->getOperand($keyArg);
        if (!$keyOp instanceof Operand\Literal || !is_int($keyOp->value)) {
            return;
        }
        $needed = $keyOp->value + 1;
        if ($needed > $array->nextFreeElement) {
            $array->nextFreeElement = $needed;
        }
    }

    private function jitVariableNativeTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::NATIVE_TYPE_MAP as $typeKey => $typeName) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $lit = new Operand\Literal($typeName);
            $lit->type = Type::string();
            $element = Variable::fromLiteral($this->context, $lit);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

    private function jitVariableTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::TYPE_MAP as $typeKey => $typeValue) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $element = Variable::fromConstantInt($this->context, $typeValue);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

    /**
     * @return array<int, Variable>
     */
    private function resolveClassNameForPseudoConst(Block $block, Operand $classOp): string
    {
        if (!$classOp instanceof Operand\Literal) {
            throw new \LogicException('Class::class requires a literal class name for JIT/AOT');
        }

        return $this->resolveJitStaticScopeClass($block, $classOp);
    }

    private function resolveJitStaticScopeClass(Block $block, Operand\Literal $classOp): string
    {
        $lc = strtolower($classOp->value);
        if ('self' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                throw new \LogicException('self:: used outside of class scope');
            }

            return $block->func->class->value;
        }
        if ('static' === $lc) {
            if ($this->context->scope->calledClassName !== '') {
                return $this->context->scope->calledClassName;
            }
            if (null !== $block->func && null !== $block->func->class) {
                return $block->func->class->value;
            }
            throw new \LogicException('static:: used outside of class scope');
        }
        if ('parent' === $lc) {
            if (null === $block->func || null === $block->func->class) {
                throw new \LogicException('parent:: used outside of class scope');
            }
            $parentLc = $this->context->type->object->parentClassLc($block->func->class->value);
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $classOp->value;
    }

    private function blockUsesThis(Block $block): bool
    {
        foreach ($block->orig->hoistedOperands as $hoisted) {
            if ('this' === JIT\OperandName::resolve($hoisted)) {
                return true;
            }
        }

        return false;
    }

    private function instanceMethodUsesThis(Block $block): bool
    {
        if (null === $block->func || null === $block->func->class) {
            return false;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return false;
        }

        return true;
    }

    /**
     * @param Operand\Literal|Operand\Variable|Operand\Temporary $receiverOp
     */
    private function initJitMethodCall(Block $block, Operand $receiverOp, string $methodName): void
    {
        assert(null !== $receiverOp->type && Type::TYPE_OBJECT === $receiverOp->type->type);
        $className = $receiverOp->type->userType
            ?? ($this->context->scope->className !== '' ? $this->context->scope->className : 'object');
        $declaringClassLc = strtolower($className);
        $methodLc = strtolower($methodName);

        if ('object' === $declaringClassLc) {
            if ('getname' === $methodLc && $this->context->functionIsRegistered('reflectionattribute::getname')) {
                $className = 'ReflectionAttribute';
                $declaringClassLc = 'reflectionattribute';
            } elseif ('getattributes' === $methodLc && $this->context->functionIsRegistered('reflectionmethod::getattributes')) {
                $className = 'ReflectionMethod';
                $declaringClassLc = 'reflectionmethod';
            }
        }

        $declaringClassId = $this->context->type->object->lookup($className);
        $visFlags = $this->context->type->object->methodVisibility($declaringClassId, $methodLc);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($this->context->scope->className !== '') {
            $callerClassLc = $this->context->scope->className;
        }
        MethodVisibility::assertCallable(
            $visFlags,
            $callerClassLc,
            $declaringClassLc,
            $className,
            $methodName
        );
        $proxyName = $declaringClassLc.'::'.$methodLc;
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
        $this->context->scope->args = [$this->context->getVariableFromOp($receiverOp)];
    }

    private function initJitStaticCall(Block $block, int $classOpIdx, int $nameOpIdx): void
    {
        $classOp = $block->getOperand($classOpIdx);
        $nameOp = $block->getOperand($nameOpIdx);
        assert($nameOp instanceof Operand\Literal);
        if (!$classOp instanceof Operand\Literal) {
            throw new \LogicException('Static call class must be a literal');
        }
        $className = $this->resolveJitStaticScopeClass($block, $classOp);
        $declaringClassLc = strtolower($className);
        $methodLc = strtolower($nameOp->value);
        $declaringClassId = $this->context->type->object->lookup($className);
        $visFlags = $this->context->type->object->methodVisibility($declaringClassId, $methodLc);
        $callerClassLc = null;
        if (null !== $block->func && null !== $block->func->class) {
            $callerClassLc = strtolower($block->func->class->value);
        } elseif ($this->context->scope->className !== '') {
            $callerClassLc = $this->context->scope->className;
        }
        MethodVisibility::assertCallable(
            $visFlags,
            $callerClassLc,
            $declaringClassLc,
            $className,
            $nameOp->value
        );
        $proxyName = $declaringClassLc.'::'.$methodLc;
        if (!$this->context->functionIsRegistered($proxyName)) {
            if ($this->context->type->object->isExternalOnlyClass($declaringClassId)) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
                $this->context->scope->args = [];

                return;
            }
            // Zend FFI is not lowered in self-host AOT bundles (#2633, StringPasswordCrypto::preloadLibcrypt).
            if ($this->shouldUseSelfHostJitStubs() && 'ffi' === $declaringClassLc) {
                $this->context->scope->toCall = $this->context->resolveFunctionProxy(
                    $className.'::'.$nameOp->value
                );
                $this->context->scope->args = [];

                return;
            }
            // bin/compile.php Zend polyfill → phpc_run_command AOT builtin (#2779, #2697).
            if (
                $this->shouldUseSelfHostJitStubs()
                && 'phpcompiler\aot\linkerprocesspolyfill' === $declaringClassLc
                && 'run' === $methodLc
            ) {
                if (!$this->context->functionIsRegistered('phpc_run_command')) {
                    throw new \LogicException(
                        'phpc_run_command internal missing for LinkerProcessPolyfill::run lowering (#2779)'
                    );
                }
                $this->context->scope->toCall = $this->context->resolveFunctionProxy('phpc_run_command');
                $this->context->scope->args = [];

                return;
            }
            throw new \LogicException("Call to undefined static method {$className}::{$nameOp->value}()");
        }
        $this->context->scope->toCall = $this->context->resolveFunctionProxy($proxyName);
        $this->context->scope->args = [];
    }

    /**
     * Static parent::__construct() from an instance method passes only declared params;
     * the callee LLVM signature may still include implicit $this when blockUsesThis().
     *
     * @param array<int, Variable> $args
     *
     * @return array<int, Variable>
     */
    /**
     * Flatten ARG_SEND list; unpack entries merge into one packed list (issue #1361).
     *
     * @param list<Variable|array{unpack: Variable}> $argEntries
     *
     * @return list<Variable>
     */
    private function finalizeJitCallArgs(array $argEntries): array
    {
        foreach ($argEntries as $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                return [JIT\HashTableHelper::mergeCallArgEntries($this->context, $argEntries)];
            }
        }

        return $argEntries;
    }

    /**
     * Static parent::instanceMethod() from an instance method passes implicit $this (#1858).
     */
    private function prependImplicitThisForStaticInstanceCall(
        Block $block,
        JIT\Call $toCall,
        array $args
    ): array {
        if (!$toCall instanceof JIT\Call\Native) {
            return $args;
        }
        if ([] === $toCall->argTypes) {
            return $args;
        }
        if ('__object__*' !== $this->context->getStringFromType($toCall->argTypes[0])) {
            return $args;
        }
        if (count($args) >= count($toCall->argTypes)) {
            return $args;
        }
        if (null === $block->func || null === $block->func->cfg) {
            return $args;
        }
        if (($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return $args;
        }
        $thisVar = $this->resolveThisVariable($block);
        if (null === $thisVar) {
            return $args;
        }

        array_unshift($args, $thisVar);

        return $args;
    }

    private function resolveThisVariable(Block $block): ?Variable
    {
        if (null === $block->func || null === $block->func->cfg) {
            return null;
        }
        foreach ($block->func->cfg->hoistedOperands as $hoisted) {
            if ('this' !== JIT\OperandName::resolve($hoisted)) {
                continue;
            }
            if (!$this->context->hasVariableOpInScopes($hoisted)) {
                return null;
            }

            return $this->context->getVariableFromOpInScopes($hoisted);
        }

        if (null !== $this->context->implicitThisArgument) {
            return $this->context->implicitThisArgument;
        }

        return null;
    }

    /**
     * @return array<int, int> LLVM argument index => VM type constraint
     */
    private function paramTypeConstraintsForNativeCall(Block $block): array
    {
        $constraints = [];
        $offset = $this->instanceMethodUsesThis($block) ? 1 : 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type) {
                continue;
            }
            if (!isset($block->paramTypeConstraints[$op->arg1])) {
                continue;
            }
            $constraints[(int) $op->arg2 + $offset] = $block->paramTypeConstraints[$op->arg1];
        }

        return $constraints;
    }

    private function collectParamDefaults(Block $block): array {
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if ($op->type !== OpCode::TYPE_ARG_RECV || null === $op->arg3) {
                continue;
            }
            if (null !== $block->variadicParamIndex && $block->variadicParamIndex === (int) $op->arg2) {
                continue;
            }
            if (!isset($block->constants[$op->arg3])) {
                continue;
            }
            $defaultIdx = $op->arg2;
            if ($this->instanceMethodUsesThis($block)) {
                ++$defaultIdx;
            }
            $defaults[$defaultIdx] = $this->jitVariableFromVmConstant($block->constants[$op->arg3]);
        }
        return $defaults;
    }

    private function jitVariableFromVmConstant(VM\Variable $vm): Variable {
        switch ($vm->type) {
            case VM\Variable::TYPE_INTEGER:
                return Variable::fromConstantInt($this->context, $vm->toInt());
            case VM\Variable::TYPE_STRING:
                $lit = new Operand\Literal($vm->toString());
                $lit->type = Type::string();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_FLOAT:
                $lit = new Operand\Literal($vm->toFloat());
                $lit->type = Type::float();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_BOOLEAN:
                $lit = new Operand\Literal($vm->toBool());
                $lit->type = Type::bool();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_NULL:
                $nullVar = new Variable(
                    $this->context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $this->context->getTypeFromString('__value__*')->constNull()
                );
                $nullVar->isNullConstant = true;

                return $nullVar;
            case VM\Variable::TYPE_ARRAY:
                return $this->jitVariableFromVmArray($vm);
            default:
                throw new \LogicException('Unsupported default parameter type for JIT (vm type ' . $vm->type . ')');
        }
    }

    private function jitNullVariable(): Variable
    {
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::pointer($this->context, $slot)
        );

        return new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    private function jitVariableFromVmArray(VM\Variable $vm): Variable
    {
        $ht = $vm->toArray();
        $jitHt = JIT\HashTableHelper::alloc($this->context);
        $var = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $jitHt
        );
        if (0 === $ht->getNumElements()) {
            return $var;
        }
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            JIT\HashTableHelper::addElement(
                $this->context,
                $var,
                $this->jitVariableFromVmConstant($value),
                $this->jitVariableFromVmConstant($key)
            );
        }

        return $var;
    }

    private function loadPropertyFetchReceiver(Operand $objOp): PHPLLVM\Value
    {
        $var = $this->context->getVariableFromOp($objOp);
        if (Variable::TYPE_OBJECT === $var->type) {
            return $this->context->helper->loadValue($var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
            );
        }

        throw new \LogicException(
            'Property fetch receiver must be object or object-valued property, got '
            .Variable::getStringType($var->type)
        );
    }

    private static function foreachContainerUserType(Operand $arrayOp): ?string
    {
        $userType = $arrayOp->type->userType ?? null;
        if (null !== $userType && '' !== $userType) {
            return $userType;
        }
        if (null !== $arrayOp->type && Variable::TYPE_HASHTABLE === Variable::getTypeFromType($arrayOp->type)) {
            $decl = $arrayOp->type->userType ?? null;
            if (null !== $decl && 0 === strcasecmp($decl, 'SplObjectStorage')) {
                return 'SplObjectStorage';
            }
        }

        return null;
    }


    /**
     * Propagate compile-time callable names through TYPE_ASSIGN (first-class callables, #1363).
     */
    private function foldCompileTimeStringFromAssign(
        Block $block,
        int $sourceSlot,
        Variable $dest,
        Variable $source
    ): void {
        if (null !== $dest->compileTimeString) {
            return;
        }
        if (null !== $source->compileTimeString) {
            $dest->compileTimeString = $source->compileTimeString;

            return;
        }
        $this->foldCompileTimeStringFromSlot($block, $sourceSlot, $dest);
    }

    private function foldCompileTimeStringFromSlot(Block $block, int $slot, Variable $dest): void
    {
        if (null !== $dest->compileTimeString) {
            return;
        }
        $resolved = $this->resolveJitCompileTimeStringSlot($block, $slot);
        if (null !== $resolved) {
            $dest->compileTimeString = $resolved;
        }
    }

    /**
     * @param array<int, true> $visited
     */
    private function resolveJitCompileTimeStringSlot(Block $block, int $slot, array &$visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (VM\Variable::TYPE_STRING !== $const->type) {
                return null;
            }

            return $const->toString();
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_ASSIGN !== $prior->type || $prior->arg2 !== $slot) {
                continue;
            }
            $resolved = $this->resolveJitCompileTimeStringSlot($block, (int) $prior->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = $this->resolveJitCompileTimeStringSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * When php-cfg assigns through a named temporary with no downstream usages, the name slot
     * may still be skipped by assignOperand; fold from the matching TYPE_ASSIGN constant (#1226).
     */
    private function foldVarFetchNameFromAssign(Block $block, int $nameSlot, Variable $nameVar): void
    {
        if (null !== $nameVar->compileTimeString) {
            return;
        }
        if (isset($block->constants[$nameSlot])) {
            $nameVar->compileTimeString = $block->constants[$nameSlot]->toString();

            return;
        }
        foreach ($block->opCodes as $prior) {
            if (
                OpCode::TYPE_ASSIGN !== $prior->type
                || $prior->arg2 !== $nameSlot
                || !isset($block->constants[$prior->arg3])
            ) {
                continue;
            }
            $nameVar->compileTimeString = $block->constants[$prior->arg3]->toString();

            return;
        }
    }

    private function varFetchDestUsedAsAssignLvalue(Block $block, int $opIndex, int $destSlot): bool
    {
        for ($j = $opIndex + 1, $n = count($block->opCodes); $j < $n; $j++) {
            $next = $block->opCodes[$j];
            if (OpCode::TYPE_ASSIGN === $next->type && $next->arg2 === $destSlot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the JIT variable for a scope slot (issue #1226).
     *
     * TYPE_VAR_FETCH arg2 is the slot holding the runtime name string, which may map to
     * multiple CFG operands; prefer a bound operand with compile-time string metadata.
     */
    private function variableFromBlockSlot(Block $block, int $slot): Variable
    {
        $operands = [];
        foreach ($block->scopedOperands() as $op) {
            if ($block->slotForOperand($op) === $slot) {
                $operands[] = $op;
            }
        }
        if ([] === $operands) {
            throw new \LogicException('No operand mapped to slot '.$slot);
        }
        usort($operands, [self::class, 'compareOperandsForSlotResolution']);
        $bound = null;
        foreach ($operands as $op) {
            if (!$this->context->hasVariableOp($op)) {
                continue;
            }
            $candidate = $this->context->getVariableFromOp($op);
            if (null !== $candidate->compileTimeString) {
                return $candidate;
            }
            if (null === $bound) {
                $bound = $candidate;
            }
        }
        if (null !== $bound) {
            return $bound;
        }

        throw new \LogicException('No JIT variable for slot '.$slot);
    }

    private function ensureJitGlobal(string $name): Variable
    {
        if (!isset($this->context->jitGlobalVariables[$name])) {
            if ('argv' === $name && null !== JIT\CliArgvGlobalInit::$global) {
                $this->context->jitGlobalVariables[$name] = JIT\CliArgvGlobalInit::load($this->context);
            } else {
                $slot = JIT\JitValueBox::alloc($this->context);
                $this->context->jitGlobalVariables[$name] = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
            }
        }

        return $this->context->jitGlobalVariables[$name];
    }

    private function ensureJitFunctionStatic(string $storageKey): Variable
    {
        if (!isset($this->context->jitFunctionStaticVariables[$storageKey])) {
            $slot = JIT\JitValueBox::alloc($this->context);
            $this->context->jitFunctionStaticVariables[$storageKey] = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $slot
            );
        }

        return $this->context->jitFunctionStaticVariables[$storageKey];
    }

    private static function operandSlotRank(\PHPCfg\Operand $op): int
    {
        $name = JIT\OperandName::resolve($op);
        if ($op instanceof \PHPCfg\Operand\Temporary && null !== $name && '' !== $name) {
            return 3;
        }
        if ($op instanceof \PHPCfg\Operand\Variable) {
            return 2;
        }

        return 1;
    }

    private static function compareOperandsForSlotResolution(\PHPCfg\Operand $a, \PHPCfg\Operand $b): int
    {
        return self::operandSlotRank($b) <=> self::operandSlotRank($a);
    }

}
