<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM / emit-helper / M3 compile-driver skip and PHP-lowering name predicates (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code isSkippedVmHotPathName}
 * through {@code isM3CompileDriverCompilerPhpLoweringName} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c compile_* hot paths; Zend/zend_execute.c VM run_frames —
 * move-only Concern extract; no new C ABI and no opcode/IR shape change.
 */
trait SkippedVmEmitHelperAndCompileDriverNames
{
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
            'compileboolconstant',
            'compilebooltemporary',
            'compilecoalesce',
            'compilenullsafe',
            'compileisset',
            'compileissetmulti',
            'compilearrayliteral',
            'compilearraydimfetchread',
            'findcoalesce',
            'resolvecoalesce',
            'resolveisset',
            'isarraydim',
            'requireoperandslot',
            'resolvesimplevariablename',
            // class-heavy sources (lib/*.php) need class lowering
            'compileclasslike',
            'compileclassbody',
            'compileglobalconst',
            'compileincludeop',
            'compileswitchasjumpifchain',
            'trycompiledefineasglobalconst',
            'compileclassconstfetch',
            'getopcodetype',
            'markcallerlocalsusedbyliteralinclude',
            'setpropertyhookregistry',
            'setknownclassreadonly',
            'setbarerethrowlines',
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
}
