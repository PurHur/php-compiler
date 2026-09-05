<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\Func as CoreFunc;
use PHPLLVM;

/**
 * M3 emit-TU Compiler/Runtime method compile + emit-smoke decl (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code compileM3EmitTuCompilerEmitSmokeNativeDecl}
 * through {@code emitM3EmitTuCompilerCompileEmitSmokeNativeFunction} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c / Zend/zend_execute_API.c — Compiler::compileEmitSmoke and
 * Runtime method pre-lower from bundled emit TU; move-only Concern extract; no new C ABI and
 * no opcode/IR shape change. Prior #1937 / #2967.
 */
trait M3EmitTuCompilerRuntimeMethodCompile
{
    private function compileM3EmitTuCompilerEmitSmokeNativeDecl(): void
    {
        if (
            !$this->shouldUseM3EmitTuNativeBridge()
            && !$this->shouldUseM3InventoryEmitDriver()
            && !$this->shouldUseM4BinCompileArgvMainNative()
            && !$this->shouldEnsureInventoryArgvParseHelperStubs()
        ) {
            return;
        }
        if ($this->shouldUseM3CompileDriverRealLowering()
            || $this->shouldUseM3EmitTuEmitHelperSpineRealLowering()
        ) {
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
        // Inventory compile_driver already require_once's Compiler.php — avoid O(module×func) scan (#2967).
        if ($this->shouldUseM3InventoryEmitDriver()) {
            $this->compileM3EmitTuCompilerMethodFromCompilerPhpFile($methodLc, $logical, $lc);

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
        $this->compileM3EmitTuCompilerMethodFromCompilerPhpFile($methodLc, $logical, $lc);
    }

    /** Lower Compiler spine method from lib/Compiler.php (inventory argv driver avoids module scan, #2967). */
    private function compileM3EmitTuCompilerMethodFromCompilerPhpFile(string $methodLc, string $logical, string $lc): void
    {
        $compilerPath = __DIR__.'/../../Compiler.php';
        if (!is_file($compilerPath)) {
            return;
        }
        if (null === $this->m3EmitTuCompilerPhpScript) {
            try {
                $this->m3EmitTuCompilerPhpScript = $this->context->runtime->parse(
                    (string) file_get_contents($compilerPath),
                    $compilerPath
                );
            } catch (\Throwable $e) {
                return;
            }
        }
        $script = $this->m3EmitTuCompilerPhpScript;
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
            static $emitHelperHostSpine = ['parse', 'compileemitsmoke'];
            if (in_array($methodLc, $emitHelperHostSpine, true)) {
                $logical = 'PHPCompiler\\Runtime::'.$methodLc;
                $lc = strtolower($logical);
                if (!isset($this->context->functions[$lc])) {
                    $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile($methodLc, $logical, $lc);
                }
            }

            return;
        }
        $logical = 'PHPCompiler\\Runtime::'.$methodLc;
        $lc = strtolower($logical);
        // Inventory emit OR M5 argv seed real-lower: host-parse lib/Runtime.php (#2967, #26756).
        // M4 bin/compile.php + M5_DRIVER_HOST can have shouldRealLower true while
        // shouldUseM3InventoryEmitDriver() is false — still need the Runtime.php path.
        if ($this->shouldUseM3InventoryEmitDriver() || $this->shouldRealLowerInventoryArgvParseSpine()) {
            if ('__construct' === $methodLc) {
                if (!isset($this->context->functions[$lc])) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null !== $stubBlock) {
                        $this->emitM3EmitTuRuntimeConstructNativeFunction(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    }
                }

                return;
            }
            // Never scan O(modules×funcs) on inventory argv links (#2967). parse/compileEmitSmoke from
            // Runtime.php; ctor/init* use native M3 via compileBlock / ensureM3EmitTuRuntimeInitSpineSymbols.
            if (in_array($methodLc, [
                'parse',
                'preparesourceforparser',
                'preprocesssourceforparse',
                'rewritesourcebeforeparser',
                'compileemitsmoke',
                'peeklastparsefailure',
                'noteparsecompilenullforscript',
            ], true)) {
                // Inventory argv / compile_driver: compileEmitSmoke must stay stubbed — full CFG
                // hits Object_::optimize() under NestedJIT and SEGV (#26756, #36144).
                // peekLastParseFailure / noteParseCompileNullForScript must stay stubbed too —
                // real lowering returns __value__* but BootstrapCompileSmokeM3Emit::echoLastParseFailureSuffix
                // structGeps __string__ fields on the call result (#36144).
                if ($this->shouldRealLowerInventoryArgvParseSpine()
                    && in_array($methodLc, [
                        'compileemitsmoke',
                        'peeklastparsefailure',
                        'noteparsecompilenullforscript',
                    ], true)
                ) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null === $stubBlock) {
                        return;
                    }
                    if ('compileemitsmoke' === $methodLc) {
                        $this->emitM3EmitTuRuntimeCompileEmitSmokeNative(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    } elseif ('noteparsecompilenullforscript' === $methodLc) {
                        $this->emitM3EmitTuRuntimeTwoObjectVoidStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    } else {
                        $this->emitM3EmitTuCompilerNullStringGetterStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    }

                    return;
                }
                if ('parse' === $methodLc && $this->shouldUseM5ParseSpineCFloor()) {
                    $this->ensureM5ParseSpineCFloorSymbols();

                    return;
                }
                // Inventory argv / M5 argv: diagnostic helpers must return native __string__* (not
                // boxed __value__*) — BootstrapCompileSmokeM3Emit::echoLastParseFailureSuffix
                // structGeps __string__ fields (#26756, #36144).
                if (in_array($methodLc, ['noteparsecompilenullforscript', 'peeklastparsefailure'], true)
                    && ($this->shouldUseM5DriverHostCompile() || $this->shouldRealLowerInventoryArgvParseSpine())
                ) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null === $stubBlock) {
                        return;
                    }
                    if ('noteparsecompilenullforscript' === $methodLc) {
                        $this->emitM3EmitTuRuntimeTwoObjectVoidStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    } else {
                        $this->emitM3EmitTuCompilerNullStringGetterStub(
                            $this->llvmInternalName($logical),
                            $logical,
                            $stubBlock
                        );
                    }

                    return;
                }
                // M5 argv seed: identity preprocess/rewrite CFG stubs (#11809 / #26756).
                if ($this->shouldUseM5DriverHostCompile()
                    && in_array($methodLc, ['preprocesssourceforparse', 'rewritesourcebeforeparser'], true)
                ) {
                    $stubBlock = $this->m3CompileDriverMainBlock ?? $this->m3EmitTuMainBlock;
                    if (null === $stubBlock) {
                        return;
                    }
                    $this->compileSkippedCompilerSplitCfgStub(
                        $this->llvmInternalName($logical),
                        $stubBlock,
                        $logical
                    );

                    return;
                }
                if ($this->shouldRealLowerInventoryArgvParseSpine()) {
                    // Drop map entry so Runtime.php lowering can run; early null stubs must not win (#26756).
                    unset(
                        $this->context->functions[$lc],
                        $this->context->functionReturnType[$lc],
                        $this->context->functionProxies[$lc]
                    );
                } elseif (isset($this->context->functions[$lc])) {
                    return;
                }
                $this->compileM3EmitTuRuntimeMethodFromRuntimePhpFile($methodLc, $logical, $lc);
                if (!isset($this->context->functions[$lc])) {
                    $this->compileM3EmitTuRuntimeMethodFromDeclareClassBlocks([$methodLc]);
                }

                return;
            }
        }
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
    }

    /** NestedJIT M5TrivialEchoScript::parseAndCompile for gen-0 functional-smoke (#26756). */
    private function ensureM5TrivialEchoScriptParseAndCompileLowered(): void
    {
        if (!$this->shouldUseM5DriverHostCompile()) {
            return;
        }
        $logical = JIT\M5TrivialEchoScript::logicalName();
        $lc = strtolower($logical);
        if (isset($this->context->functions[$lc])) {
            return;
        }
        $path = __DIR__.'/../M5TrivialEchoScript.php';
        if (!is_file($path)) {
            return;
        }
        try {
            $script = $this->context->runtime->parse((string) file_get_contents($path), $path);
        } catch (\Throwable $e) {
            return;
        }
        $savedClassId = $this->context->scope->classId;
        $savedClassName = $this->context->scope->className;
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\JIT\\M5TrivialEchoScript');
        $this->context->scope->className = 'phpcompiler\\jit\\m5trivialechoscript';
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $className = $this->cfgOperandClassName($child->name);
            $classLc = null === $className
                ? null
                : strtolower(str_replace('/', '\\', ltrim($className, '\\')));
            if (null === $classLc || 'phpcompiler\\jit\\m5trivialechoscript' !== $classLc) {
                continue;
            }
            foreach ($child->stmts->children as $bodyChild) {
                if (!$bodyChild instanceof Op\Stmt\ClassMethod) {
                    continue;
                }
                if (strtolower($bodyChild->func->name) !== 'parseandcompile') {
                    continue;
                }
                if (null === $bodyChild->func->cfg) {
                    break;
                }
                $compiled = $this->context->runtime->compileFunc($logical, $bodyChild->func);
                if ($compiled instanceof CoreFunc\PHP) {
                    JIT\NestedJitCompileScope::run($this->context, function () use ($compiled, $logical): void {
                        $this->compileBlock($compiled->block, $logical);
                    });
                }
                $this->context->scope->classId = $savedClassId;
                $this->context->scope->className = $savedClassName;

                return;
            }
        }
        $this->context->scope->classId = $savedClassId;
        $this->context->scope->className = $savedClassName;
    }

    /** Lower Runtime::parse / compileEmitSmoke from lib/Runtime.php for inventory argv driver (#2967). */
    private function compileM3EmitTuRuntimeMethodFromRuntimePhpFile(string $methodLc, string $logical, string $lc): void
    {
        $runtimePath = __DIR__.'/../../Runtime.php';
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
                // Isolate builder/block maps — host-lowering Runtime.php mid argv compile
                // otherwise leaves parentless instructions at module verify (#26756).
                JIT\NestedJitCompileScope::run($this->context, function () use ($compiled, $logical): void {
                    $this->compileBlock($compiled->block, $logical);
                });
            }

            return;
        }
        $savedClassId = $this->context->scope->classId;
        $savedClassName = $this->context->scope->className;
        $this->context->scope->classId = $this->context->type->object->lookup('PHPCompiler\\Runtime');
        $this->context->scope->className = 'phpcompiler\\runtime';
        foreach ($script->main->cfg->children as $child) {
            if (!$child instanceof Op\Stmt\Class_) {
                continue;
            }
            $className = $this->cfgOperandClassName($child->name);
            $classLc = null === $className
                ? null
                : strtolower(str_replace('/', '\\', ltrim($className, '\\')));
            if (null === $classLc || !in_array($classLc, ['phpcompiler\\runtime', 'runtime'], true)) {
                continue;
            }
            foreach ($child->stmts->children as $bodyChild) {
                if (!$bodyChild instanceof Op\Stmt\ClassMethod) {
                    continue;
                }
                if (strtolower($bodyChild->func->name) !== $methodLc) {
                    continue;
                }
                if (null === $bodyChild->func->cfg) {
                    break;
                }
                $compiled = $this->context->runtime->compileFunc($logical, $bodyChild->func);
                if ($compiled instanceof CoreFunc\PHP) {
                    JIT\NestedJitCompileScope::run($this->context, function () use ($compiled, $logical): void {
                        $this->compileBlock($compiled->block, $logical);
                    });
                }
                $this->context->scope->classId = $savedClassId;
                $this->context->scope->className = $savedClassName;

                return;
            }
        }
        $this->context->scope->classId = $savedClassId;
        $this->context->scope->className = $savedClassName;
    }

    private function cfgOperandClassName(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }
        if ($operand instanceof Operand\Variable) {
            return $this->cfgOperandClassName($operand->name);
        }

        return null;
    }

    /**
     * Find Runtime methods on bundled declare_class blocks (private init* may be absent from queue).
     *
     * @param list<string> $methodLcs lowercase method names without class prefix
     */
    private function compileM3EmitTuRuntimeMethodFromDeclareClassBlocks(array $methodLcs): void
    {
        if (
            !$this->shouldUseM3EmitTuNativeBridge()
            && !$this->shouldRealLowerInventoryArgvParseSpine()
        ) {
            return;
        }
        $allowed = array_fill_keys($methodLcs, true);
        $blocks = [];
        if (null !== $this->m3CompileDriverMainBlock) {
            $blocks[] = $this->m3CompileDriverMainBlock;
        }
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

}
