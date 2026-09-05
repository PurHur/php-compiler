<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\OpCode;
use PHPCompiler\Config;

/**
 * Self-host / emit-helper / vendor-prelink emit policy predicates (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code shouldUseSelfHostJitStubs}
 * through {@code isSelfHostBundledClassPrefix} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c compile-time class/body eligibility; Zend/zend_execute_API.c
 * executor globals for AOT/self-host stub vs real-lower gates — move-only Concern extract;
 * no new C ABI and no opcode/IR shape change. Prior #816 / #1983 / #2600 / #3036 / #3053.
 */
trait SelfHostEmitHelperAndVendorPrelinkPolicy
{
    /** Self-host AOT sets PHP_COMPILER_SELFHOST_AOT=1 (#816, #557). */
    private function shouldUseSelfHostJitStubs(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** User script AOT via bin/compile.php: real closure lowering (#3725). */
    private function shouldStubClosureLowering(): bool
    {
        $userScript = Config::getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScript || 'true' === strtolower((string) $userScript)) {
            return false;
        }
        if ($this->shouldUseVendorPrelinkJitStubs()) {
            return true;
        }

        return $this->shouldUseSelfHostJitStubs();
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
        $flag = Config::getenv('PHP_COMPILER_EMIT_HELPER_LINK');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * Inventory compile_driver.php emit-helper link: stub argv driver bodies; native emit bridge only (#2540).
     */
    private function shouldStubInventoryEmitHelperBundledBodies(): bool
    {
        return $this->shouldUseM3InventoryEmitDriver() && $this->shouldUseEmitHelperLinkStubs();
    }

    /**
     * Inventory emit-helper link: parse/CFG spine stub retired on executable argv drivers (#8706).
     * Mirror {@see shouldPrelowerRuntimeStandaloneForKeepObjectEmit} — gen-0/spine/inventory/M4
     * argv links must real-lower Runtime::parse for honest native compile (#2967, #3046, #8708).
     */
    private function shouldStubInventoryEmitParseCompileSpine(): bool
    {
        if ($this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            // M4 bin/compile.php without inventory emit keeps stub spine (#2930); inventory emit needs sidecars + parse (#2967).
            return !$this->shouldUseM3InventoryEmitDriver();
        }
        if (!$this->shouldStubInventoryEmitHelperBundledBodies()) {
            return false;
        }
        if ($this->shouldUseSelfHostExecutableEmit()
            || $this->shouldUseVendorPrelinkExecutableEmit()
            || $this->shouldUseM4BinCompileArgvMainNative()
            || ($this->shouldUseM3CompileDriverMainNative() && $this->shouldUseEmitHelperLinkStubs())
        ) {
            return false;
        }

        return true;
    }

    /**
     * M5 vendor prelink: AOT-compile literal-require vendor bundles without full class lowering (#1416).
     * Set by script/bootstrap-vendor-objects.php during --compile only.
     */
    private function shouldUseVendorPrelinkJitStubs(): bool
    {
        $flag = Config::getenv('PHP_COMPILER_VENDOR_PRELINK');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * M5 vendor cold boot: argv compile drivers must real-lower Runtime::standalone so
     * PHP_COMPILER_KEEP_OBJECT_FILE=1 leaves buildBase.o (not sidecar copy only — #3036).
     */
    private function shouldPrelowerRuntimeStandaloneForKeepObjectEmit(): bool
    {
        if (!$this->shouldUseM3CompileDriverRealLowering()) {
            return false;
        }
        // Sidecar host-compiles (bin/compile.php blob, vendor bundles) must keep standalone stubbed.
        if ('1' === (string) Config::getenv('PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD')) {
            return false;
        }
        if ('1' === (string) Config::getenv('PHP_COMPILER_M3_EMIT_TU')) {
            return false;
        }

        return $this->shouldUseM4BinCompileArgvMainNative()
            || ($this->shouldUseM3CompileDriverMainNative() && $this->shouldUseEmitHelperLinkStubs())
            || $this->shouldUseVendorPrelinkExecutableEmit();
    }

    /** M5 vendor argv compile: emit-helper spine real-lowers parse/compile/standalone (#3036). */
    private function shouldUseVendorPrelinkObjectEmit(): bool
    {
        if (!$this->shouldUseVendorPrelinkJitStubs()) {
            return false;
        }
        $keep = Config::getenv('PHP_COMPILER_KEEP_OBJECT_FILE');

        return '1' === $keep || 'true' === strtolower((string) $keep);
    }

    /** M5 spine link: prelinked vendor .o + native executable (not object-only — #3052). */
    private function shouldUseVendorPrelinkExecutableEmit(): bool
    {
        if (!$this->shouldUseVendorPrelinkJitStubs()) {
            return false;
        }
        if ($this->shouldUseVendorPrelinkObjectEmit()) {
            return true;
        }
        $selfhost = Config::getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $selfhost || 'true' === strtolower((string) $selfhost);
    }

    /** Gen-0 argv driver + self-host link: real-lower standalone when not vendor-prelink (#3053). */
    private function shouldUseSelfHostExecutableEmit(): bool
    {
        if ($this->shouldUseVendorPrelinkJitStubs()) {
            return false;
        }
        $selfhost = Config::getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $selfhost || 'true' === strtolower((string) $selfhost);
    }

    private function shouldSkipExternalClassBodyLowering(int $classId): bool
    {
        if ($this->isBundledSuperglobalsClass($classId)) {
            return true;
        }
        $className = strtolower($this->context->type->object->classNameForId($classId));
        if ('' !== $className && str_ends_with($className, 'jithelper')) {
            return false;
        }
        if ('' === $className) {
            return $this->shouldUseSelfHostJitStubs()
                || $this->shouldUseEmitHelperLinkStubs()
                || $this->shouldUseM3EmitTuNativeBridge()
                || $this->shouldUseVendorPrelinkJitStubs();
        }
        if ($this->isBundledJitExternalClassPrefix($className)) {
            return true;
        }
        if ($this->shouldUseEmitHelperLinkStubs()
            || $this->shouldUseM3EmitTuNativeBridge()
            || $this->shouldUseVendorPrelinkJitStubs()
        ) {
            return true;
        }
        // bin/compile.php sets PHP_COMPILER_SELFHOST_AOT=1 for LLVM stability (#2600), but user
        // script classes (including synthetic AnonymousClass@line) still need method lowering (#3098).
        if ($this->shouldUseSelfHostJitStubs()) {
            return $this->isSelfHostBundledClassPrefix($className);
        }

        return false;
    }

    private function isBundledJitExternalClassPrefix(string $classLc): bool
    {
        return str_starts_with($classLc, 'phpcfg\\')
            || str_starts_with($classLc, 'phptypes\\')
            || str_starts_with($classLc, 'phpllvm\\')
            || str_starts_with($classLc, 'nikic\\');
    }

    private function isSelfHostBundledClassPrefix(string $classLc): bool
    {
        return $this->isBundledJitExternalClassPrefix($classLc)
            || str_starts_with($classLc, 'phpcompiler\\')
            || 'compiler' === $classLc
            || 'runtime' === $classLc
            || str_starts_with($classLc, 'ircmaxell\\');
    }
}
