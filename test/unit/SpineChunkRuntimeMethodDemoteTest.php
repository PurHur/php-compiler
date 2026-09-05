<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\SpineChunkRuntimeMethodDemote;
use PHPUnit\Framework\TestCase;

/**
 * SPINE_CHUNK Runtime method demote capacity gate (#36387).
 *
 * @group aot-lint
 */
final class SpineChunkRuntimeMethodDemoteTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK);
        unset($_ENV[ExternalMethodBind::ENV_SPINE_CHUNK], $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK]);
        parent::tearDown();
    }

    public function testShouldDemoteHubCapacityClassesUnderSpineChunk(): void
    {
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Runtime'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Block'));
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Runtime'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\runtime'));
        // Top-level Block — NestedJIT hashtable→native-long assign trap under SPINE_CHUNK (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Block'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\block'));
        // Entire PHPCompiler\VM\* namespace — NestedJIT traps as hub singletons / packed hubs (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\Variable'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\HashTable'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\TypeCheck'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\Context'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\ErrorReporter'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\ClassEntry'));
        // AOT\* NestedJIT OOM / try-catch null insert / computed include under SPINE_CHUNK (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AOT\\HelperRuntimeCache'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AOT\\ProjectGraph'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AOT\\AotEmitFastExit'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\aot\\composervendormap'));
        // Compiler\* / Web\* peer TUs — NestedJIT gaps + OOM under SPINE_CHUNK (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Compiler\\InheritanceVariance'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Compiler\\TraitClassConstConflictCheck'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\compiler\\overridevalidator'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Web\\MultipartParser'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\web\\responsecontext'));
        // Ast\* / Cli\* / SourcePreprocessor\* — preg_replace_callback / OOM / string-arg (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Ast\\PipeOperatorDesugar'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\ast\\clonewithdesugar'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Cli\\PhpcBuild'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\cli\\phpcrun'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\SourcePreprocessor\\PropertyHooks'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\sourcepreprocessor\\propertyhooks'));
        // JIT\* peer TUs — isset/goto-resume/segfault under NestedJIT (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT\\Analyzer'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT\\Variable'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT\\Builtin\\CallArgv'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\jit\\arrayfilterllvm'));
        // Top-level VM.php — NestedJIT OOM without demote; emits after (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM'));
        // ext\* peer TUs — NestedJIT segfault rc=139 on bcmath class/JIT packs (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ext\\bcmath\\NumberAdd'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\ext\\bcmath\\jitbcmath'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ext\\standard\\Strlen'));
        // Builtin*/OpCode/ModuleAbstract/Frame/Config — NestedJIT SEGV / LLVM OOM (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\BuiltinParamNames'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\BuiltinInternalArgInfo'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\builtinbyrefparams'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\OpCode'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ModuleAbstract'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Frame'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Config'));
        // Func*/Cfg*/Lint*/Visitor* — NestedJIT SEGV under SPINE_CHUNK (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Func\\Internal'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\func\\php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Cfg\\OpSubBlockAccess'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Lint\\Linter'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Visitor\\VoidCastResolver'));
        // Top-level JIT Concern traits (namespace PHPCompiler) — host CFG OOM without demote (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompileBlockInternal'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AssignOperand'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\InitJitMethodCall'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompileClassAndTraitUses'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\InitJitStaticCall'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompileIncDecAndConcatFlatten'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\DateTimeConstructAndMutationMeta'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\DomCompileTimeTagMeta'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CoerceReturnPropertyDeclaringAndByRef'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\PropertyIncDecCompile'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CallResultCompileTimePropagate'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\PropertyFetchCoalesceAndCompileTimeString'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CallResultOperandAssign'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ByRefFormalAssignAndCallArgAdapt'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ResolveJitOutgoingCall'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\LocalReleaseUnsetAndVarFetchDest'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ScriptGlobalAssignAndLvalueResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AssignRefSharedBoxAndClosureInvoke'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ClassConstEnumAndFunctionStatic'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AssignOperandValueMetaAndGeneratorField'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JitConstructAssignedAndNativeLongLocal'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\NestedVmHelperAndThisResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\BoundMethodInstanceCallResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ParamConstraintsAndRuntimeNewInit'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ClosureThisAndStaticScopeResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\BinaryOpConcatAndTypeMapConstants'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\SubBlockCatchFinallyAndGeneratorResume'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ListUnpackClassDeclareAndIncludeAssign'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\SkippedSplitCfgAndTernaryMergeHelpers'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\SkippedHotPathAndRealLoweringNames'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\SkippedVmEmitHelperAndCompileDriverNames'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompileSkippedOpcodeVmAndCfgBranchStubs'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3EmitTuSpineNativeTryAndCfgParamTypes'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3EmitTuCompilerAndRuntimeVoidStubs'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3EmitTuAndCompileDriverMainNative'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3EmitTuRuntimeSpineDeclsAndCompileDeps'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3EmitTuRuntimeParseAndInitSpine'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VmSmokeAndRuntimeM3NativeStubs'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3EmitTuCompilerRuntimeMethodCompile'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3EmitTuRuntimeSpineStubNative'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ValueBoxCoalesceAndConcatHelpers'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\SelfHostEmitHelperAndVendorPrelinkPolicy'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompileBlockPhpLoweringAndClosurePrep'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\M3M4M5CompileDriverEmitPolicy'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompileBlockDispatchAndReflectionMeta'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JitCompileEntryRunQueueAndBlockStorage'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CompileBlockInternal'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CompileClassAndTraitUses'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\InitJitStaticCall'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CompileIncDecAndConcatFlatten'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\DateTimeConstructAndMutationMeta'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\DomCompileTimeTagMeta'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CoerceReturnPropertyDeclaringAndByRef'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\PropertyIncDecCompile'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CallResultCompileTimePropagate'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\PropertyFetchCoalesceAndCompileTimeString'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CallResultOperandAssign'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ByRefFormalAssignAndCallArgAdapt'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ResolveJitOutgoingCall'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\LocalReleaseUnsetAndVarFetchDest'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ScriptGlobalAssignAndLvalueResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\AssignRefSharedBoxAndClosureInvoke'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ClassConstEnumAndFunctionStatic'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\AssignOperandValueMetaAndGeneratorField'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\JitConstructAssignedAndNativeLongLocal'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\NestedVmHelperAndThisResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\BoundMethodInstanceCallResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ParamConstraintsAndRuntimeNewInit'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ClosureThisAndStaticScopeResolve'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\BinaryOpConcatAndTypeMapConstants'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\SubBlockCatchFinallyAndGeneratorResume'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ListUnpackClassDeclareAndIncludeAssign'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\SkippedSplitCfgAndTernaryMergeHelpers'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\SkippedHotPathAndRealLoweringNames'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\SkippedVmEmitHelperAndCompileDriverNames'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CompileSkippedOpcodeVmAndCfgBranchStubs'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3EmitTuSpineNativeTryAndCfgParamTypes'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3EmitTuCompilerAndRuntimeVoidStubs'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3EmitTuAndCompileDriverMainNative'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3EmitTuRuntimeSpineDeclsAndCompileDeps'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3EmitTuRuntimeParseAndInitSpine'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\VmSmokeAndRuntimeM3NativeStubs'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3EmitTuCompilerRuntimeMethodCompile'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3EmitTuRuntimeSpineStubNative'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\ValueBoxCoalesceAndConcatHelpers'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\SelfHostEmitHelperAndVendorPrelinkPolicy'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CompileBlockPhpLoweringAndClosurePrep'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\M3M4M5CompileDriverEmitPolicy'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\CompileBlockDispatchAndReflectionMeta'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\JitCompileEntryRunQueueAndBlockStorage'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CompileBlockInternal.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CompileClassAndTraitUses.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/InitJitStaticCall.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CompileIncDecAndConcatFlatten.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/DateTimeConstructAndMutationMeta.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/DomCompileTimeTagMeta.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/PropertyIncDecCompile.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CoerceReturnPropertyDeclaringAndByRef.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CallResultCompileTimePropagate.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/PropertyFetchCoalesceAndCompileTimeString.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CallResultOperandAssign.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ByRefFormalAssignAndCallArgAdapt.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ResolveJitOutgoingCall.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/LocalReleaseUnsetAndVarFetchDest.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ScriptGlobalAssignAndLvalueResolve.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/AssignRefSharedBoxAndClosureInvoke.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ClassConstEnumAndFunctionStatic.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/AssignOperandValueMetaAndGeneratorField.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/JitConstructAssignedAndNativeLongLocal.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/NestedVmHelperAndThisResolve.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/BoundMethodInstanceCallResolve.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ParamConstraintsAndRuntimeNewInit.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ClosureThisAndStaticScopeResolve.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/BinaryOpConcatAndTypeMapConstants.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/SubBlockCatchFinallyAndGeneratorResume.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ListUnpackClassDeclareAndIncludeAssign.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/SkippedSplitCfgAndTernaryMergeHelpers.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/SkippedHotPathAndRealLoweringNames.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/SkippedVmEmitHelperAndCompileDriverNames.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CompileSkippedOpcodeVmAndCfgBranchStubs.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3EmitTuSpineNativeTryAndCfgParamTypes.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3EmitTuCompilerAndRuntimeVoidStubs.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3EmitTuAndCompileDriverMainNative.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3EmitTuRuntimeSpineDeclsAndCompileDeps.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3EmitTuRuntimeParseAndInitSpine.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/VmSmokeAndRuntimeM3NativeStubs.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3EmitTuCompilerRuntimeMethodCompile.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3EmitTuRuntimeSpineStubNative.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/ValueBoxCoalesceAndConcatHelpers.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/SelfHostEmitHelperAndVendorPrelinkPolicy.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CompileBlockPhpLoweringAndClosurePrep.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/M3M4M5CompileDriverEmitPolicy.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/CompileBlockDispatchAndReflectionMeta.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT/Concern/JitCompileEntryRunQueueAndBlockStorage.php'));
        // Doctor.php — NestedJIT OOM without demote; hollow + demote emits (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Doctor'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemoteTarget('PHPCompiler\\Doctor'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/Doctor.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/CompilerVersion.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('ext/dom/VmDom.php'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/Compiler.php'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('lib/JIT.php'));
        // Measured post-demote hubs now emit (soap literals + qualified-ns hollow, #36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('ext/soap/VmSoapClient.php'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::oversizeSingletonCanEmit('ext/standard/VmDateTimeNative.php'));
        // Compiler / CompilerVersion / JIT stay live — Compiler/JIT need file splits for host CFG.
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Compiler'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompilerVersion'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ExtensionRegistry'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\PHPTypes\\CompilerTypeReconstructor'));
    }

    public function testDemoteMethodBlockLeavesOnlyReturnVoid(): void
    {
        $block = new Block(null);
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, 0));
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, 1));
        $block->blocks[] = new Block(null);
        SpineChunkRuntimeMethodDemote::demoteMethodBlock($block, 'initparsepipeline');
        $this->assertCount(1, $block->opCodes);
        $this->assertSame(OpCode::TYPE_RETURN_VOID, $block->opCodes[0]->type);
        $this->assertSame([], $block->blocks);
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemotedStub($block));
    }

    public function testIsDemotedStubRejectsNonEmptyBodies(): void
    {
        $block = new Block(null);
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, 0));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::isDemotedStub($block));
        $block->opCodes = [];
        $block->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));
        $block->blocks[] = new Block(null);
        $this->assertFalse(SpineChunkRuntimeMethodDemote::isDemotedStub($block));
    }
}
