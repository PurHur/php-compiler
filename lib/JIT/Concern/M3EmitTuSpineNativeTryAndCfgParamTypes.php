<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPLLVM;
use PHPTypes\Type;

/**
 * M3 emit-TU spine native try-compile + CFG param LLVM typing (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code isM3EmitTuCompilerSpineLoweringName}
 * through {@code llvmTypeForCfgParam} so the hub shrinks toward split-TU iterability
 * under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c / Zend/zend_execute_API.c — Runtime/Compiler spine native
 * bridges and CFG formal → LLVM type mapping for NestedJIT; move-only Concern extract;
 * no new C ABI and no opcode/IR shape change. Prior #1937 / #2442 / #2967.
 */
trait M3EmitTuSpineNativeTryAndCfgParamTypes
{
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
            'setpropertyhookregistry',
            'setknownclassreadonly',
            'setbarerethrowlines',
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
        if (str_ends_with($emitLc, '\\runtime::loadjitcontext')) {
            if ($this->shouldUseM3CompileDriverRealLowering() && !$this->shouldStubM3InventoryEmitJitSpineMethods()) {
                return null;
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($emitLc, '\\runtime::createjit')
            || str_ends_with($emitLc, '\\runtime::jitcontextforloadjit')
            || str_ends_with($emitLc, '\\runtime::loadjitcompilemodulefuncs')
        ) {
            if ($this->shouldUseM3CompileDriverRealLowering() && !$this->shouldStubM3InventoryEmitJitSpineMethods()) {
                return null;
            }

            return $this->emitM3EmitTuRuntimeInitVoidStub($internalName, $logicalName, $block);
        }
        if (str_ends_with($emitLc, '\\runtime::loadjit')
            || str_ends_with($emitLc, '\\runtime::jitemitinplace')
        ) {
            if ($this->shouldUseM3CompileDriverRealLowering()) {
                return null;
            }

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
        if ($this->shouldStubInventoryEmitHelperBundledBodies()) {
            foreach (['parse', 'preparesourceforparser', 'preprocesssourceforparse', 'rewritesourcebeforeparser'] as $stubSuffix) {
                if (str_ends_with($lower, '\\runtime::'.$stubSuffix)) {
                    return false;
                }
            }
        }
        foreach ([
            '__construct',
            'initparsepipeline',
            'initcompiler',
            'initvmcontext',
            'loadcoremodules',
            'preparesourceforparser',
            'parse',
            'compile',
            'compileemitsmoke',
            'parseandcompile',
            'parseandcompileemitsmoke',
            'parseandcompilefile',
            'noteparsecompilenullforscript',
            'peeklastparsefailure',
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
        foreach ($block->func->params as $idx => $param) {
            $args[] = $this->llvmTypeForCfgParam($param, $block, $idx);
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

    /** VM Variable handles use boxed __value__* ABI in nested php-in-PHP JIT helpers (#16565). */
    private function isCfgVmVariableParamType(?Type $type): bool
    {
        if (null === $type || Type::TYPE_OBJECT !== $type->type) {
            return false;
        }
        $name = strtolower(ltrim($type->userType ?? '', '\\'));

        return 'phpcompiler\\vm\\variable' === $name
            || str_ends_with($name, '\\vm\\variable')
            || 'variable' === $name;
    }

    /** VM HashTable handles use __hashtable__* ABI in nested php-in-PHP JIT helpers (#21109). */
    private function isCfgVmHashTableParamType(?Type $type): bool
    {
        if (null === $type || Type::TYPE_OBJECT !== $type->type) {
            return false;
        }
        $name = strtolower(ltrim($type->userType ?? '', '\\'));

        return 'phpcompiler\\vm\\hashtable' === $name
            || str_ends_with($name, '\\vm\\hashtable')
            || 'hashtable' === $name;
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

    /**
     * User class-typed object formals use boxed {@see __value__*} at the LLVM ABI (#24429).
     * Compiler/runtime methods keep native {@see __object__*} (DOM init, spine helpers).
     */
    private function cfgParamUsesBoxedUserObjectFormal(?Block $block, Type $rawType): bool
    {
        if (Type::TYPE_OBJECT !== $rawType->type) {
            return false;
        }
        if ($this->isCfgObjectIdentityParamType($rawType) || $this->isCfgVmVariableParamType($rawType)) {
            return false;
        }
        if ($this->cfgEnclosingFuncIsCompilerInternal($block)) {
            return false;
        }
        $userType = strtolower(ltrim((string) ($rawType->userType ?? ''), '\\'));

        return '' !== $userType && !\in_array($userType, ['object', 'mixed', 'stdclass'], true);
    }

    private function cfgEnclosingFuncIsCompilerInternal(?Block $block): bool
    {
        if (null === $block || null === $block->func || null === $block->func->class) {
            return false;
        }
        $class = strtolower(ltrim((string) $block->func->class->value, '\\'));

        return str_starts_with($class, 'phpcompiler\\');
    }

    private function llvmTypeForCfgParam(
        \PHPCfg\Op\Expr\Param $param,
        ?Block $block = null,
        ?int $paramIdx = null
    ): PHPLLVM\Type {
        if (
            null !== $block
            && null !== $paramIdx
            && $this->cfgParamIsImplicitNullable($block, $paramIdx)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        // Variadic formals are always a packed HT — including `&...$args`, where by-ref
        // applies to *elements*, not the array slot (Zend zend_compile / #27407). Checking
        // byRef first made AOT declare `__value__*` while the Variable stayed TYPE_HASHTABLE,
        // so `__value__writeHashtable` saw a value box where a hashtable pointer was required.
        if ($param->variadic) {
            return $this->context->getTypeFromString('__hashtable__*');
        }
        if ($param->byRef) {
            return $this->context->getTypeFromString('__value__*');
        }
        if ($this->cfgParamDeclaredTypeUsesDnfShape($param)) {
            return $this->context->getTypeFromString('__value__*');
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
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmVariableParamType($declared)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmHashTableParamType($declared)
        ) {
            return $this->context->getTypeFromString('__hashtable__*');
        }
        if (null !== $declared && $this->isCfgObjectIdentityParamType($declared)) {
            return $this->context->getTypeFromString('__object__*');
        }
        $rawType = $this->rawTypeFromCfgParam($param);
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmVariableParamType($rawType)
        ) {
            return $this->context->getTypeFromString('__value__*');
        }
        if (
            JIT\NestedJitCompileScope::isActive()
            && $this->isCfgVmHashTableParamType($rawType)
        ) {
            return $this->context->getTypeFromString('__hashtable__*');
        }
        if ($this->isCfgObjectIdentityParamType($rawType)) {
            return $this->context->getTypeFromString('__object__*');
        }
        if ($this->cfgParamUsesBoxedUserObjectFormal($block, $rawType)) {
            return $this->context->getTypeFromString('__value__*');
        }
        $callback = $this->callbackTypeFromPhptype($rawType);
        if (null !== $callback) {
            return $this->context->getTypeFromString($callback);
        }

        return $this->context->getTypeFromType($rawType);
    }

    /** Stub VM hot-path methods whose opcode switches crash LLVM 9 during self-host AOT (#816). */
}
