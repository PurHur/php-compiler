<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * M3 emit-TU link-time sidecar registration (#36403).
 *
 * Extracted from {@see \PHPCompiler\JIT}: repo-root resolution, trivial-echo /
 * inventory sidecar host-compile + registerLinktime, and bin/vm stub fallback.
 * Concern trait (same namespace as parent) so relative Config / JIT helpers resolve.
 */
trait M3EmitTuSidecarLinktime
{
    /**
     * Live repo root for M3 sidecar registration (#3046, #3012).
     *
     * Self-host AOT may bake compile-time {@see __DIR__} as /compiler/lib from Docker/prelinked gen-0;
     * walk env/cwd/markers so gen-2→gen-3 links register host-relative sidecar paths.
     */
    private function m3EmitTuRuntimeRepoRoot(): string
    {
        static $resolved = null;
        if (is_string($resolved) && '' !== $resolved) {
            return $resolved;
        }
        $fromEnv = Config::getenv('PHP_COMPILER_REPO_ROOT');
        if (is_string($fromEnv) && '' !== $fromEnv) {
            $real = realpath($fromEnv);
            if (false !== $real && is_readable($real.'/bin/compile.php') && is_readable($real.'/lib/JIT.php')) {
                return $resolved = str_replace('\\', '/', $real);
            }
        }
        /** @var list<string> $candidates */
        $candidates = [];
        $cwd = getcwd();
        if (is_string($cwd) && '' !== $cwd) {
            $candidates[] = $cwd;
        }
        if (is_string($this->context->m3EmitTuTrivialEchoPath ?? null) && '' !== $this->context->m3EmitTuTrivialEchoPath) {
            $candidates[] = dirname($this->context->m3EmitTuTrivialEchoPath);
        }
        $candidates[] = dirname(__DIR__);
        $seen = [];
        foreach ($candidates as $start) {
            if (!is_string($start) || '' === $start || isset($seen[$start])) {
                continue;
            }
            $seen[$start] = true;
            $dir = str_replace('\\', '/', $start);
            for ($depth = 0; $depth < 16; ++$depth) {
                if (is_readable($dir.'/bin/compile.php') && is_readable($dir.'/lib/JIT.php')) {
                    $real = realpath($dir);

                    return $resolved = false !== $real ? str_replace('\\', '/', $real) : $dir;
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }
        $fallback = str_replace('\\', '/', dirname(__DIR__));
        $real = realpath($fallback);

        return $resolved = false !== $real ? str_replace('\\', '/', $real) : $fallback;
    }

    private function m3EmitTuRepoPath(string $relativePath): string
    {
        return $this->m3EmitTuRuntimeRepoRoot().'/'.ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    /** Host-compile emit-helper probe source and cache linked AOT bytes at link time (#2559, #2567, #2618). */
    /** Probe-only: skip emit-helper link-time sidecars for inventory argv honesty check (#15604). */
    private function shouldSkipM3InventoryEmitHelperSidecarsForProbe(): bool
    {
        if (!$this->shouldUseM3InventoryEmitDriver() || $this->shouldUseEmitHelperLinkStubs()) {
            return false;
        }
        $flag = Config::getenv('PHP_COMPILER_M3_INVENTORY_NO_EMIT_HELPER_SIDECAR');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /** Default-on fast inventory link: argv driver only needs compiler_minimal-scale sidecars (#1492). */
    private function shouldUseM3InventoryMinimalSidecars(): bool
    {
        foreach (['BOOTSTRAP_INVENTORY_DRIVER_FULL', 'PHP_COMPILER_M3_INVENTORY_FULL_SIDECARS'] as $fullKey) {
            $full = getenv($fullKey);
            if ('1' === $full || 'true' === strtolower((string) $full)) {
                return false;
            }
        }
        foreach (['PHP_COMPILER_M3_INVENTORY_MINIMAL_SIDECARS', 'BOOTSTRAP_INVENTORY_MINIMAL_SIDECARS'] as $envKey) {
            $v = getenv($envKey);
            if ('0' === $v || 'false' === strtolower((string) $v)) {
                return false;
            }
        }

        return true;
    }

    private function m3EmitTuForceSidecarHostCompile(): bool
    {
        foreach (['BOOTSTRAP_FORCE_COMPILER_LIB_SIDECAR_REGEN', 'PHP_COMPILER_M3_FORCE_SIDECAR_HOST_COMPILE'] as $envKey) {
            $v = getenv($envKey);
            if ('1' === $v || 'true' === strtolower((string) $v)) {
                return true;
            }
        }

        return false;
    }

    /** Reuse committed compiler_lib sidecar without honest stamp match (skip multi-hour host-compile — #8703). */
    private function m3EmitTuReuseStaleCompilerLibSidecar(): bool
    {
        if ($this->m3EmitTuForceSidecarHostCompile()) {
            return false;
        }
        if ($this->shouldUseM3InventoryMinimalSidecars()) {
            return true;
        }
        foreach (['PHP_COMPILER_M3_REUSE_STALE_COMPILER_LIB_SIDECAR', 'BOOTSTRAP_ALLOW_STALE_SIDECAR'] as $envKey) {
            $v = getenv($envKey);
            if ('1' === $v || 'true' === strtolower((string) $v)) {
                return true;
            }
        }

        return true;
    }

    /**
     * @return list<string> repo-relative sidecar paths to try for stale compiler_lib reuse
     */
    private function m3EmitTuCompilerLibSidecarFallbackPaths(string $repoRoot): array
    {
        return [
            $repoRoot.'/build/.m3_compiler_lib_aot_blob',
            $repoRoot.'/prelinked/bootstrap-gen0/compiler_lib_aot_blob',
        ];
    }

    private function m3EmitTuTryRegisterStaleCompilerLibSidecar(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        string $code,
        string $repoRoot
    ): bool {
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL !== $sidecarRel
            || !$this->m3EmitTuReuseStaleCompilerLibSidecar()) {
            return false;
        }
        foreach ($this->m3EmitTuCompilerLibSidecarFallbackPaths($repoRoot) as $blobPath) {
            if (!is_readable($blobPath)) {
                continue;
            }
            $aotBytes = file_get_contents($blobPath);
            if (!is_string($aotBytes) || '' === $aotBytes) {
                continue;
            }
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                $this->context,
                $repoRoot,
                $code,
                $aotBytes,
                $sidecarRel,
                $sentinelLogical,
                true,
                $this->m3EmitTuSidecarSourcePathNorm($path)
            );

            return true;
        }

        return false;
    }

    /**
     * @return list<string> candidate blob paths for an existing sidecar (build/ then prelinked gen-0)
     */
    private function m3EmitTuExistingSidecarBlobPaths(string $repoRoot, string $sidecarRel): array
    {
        $paths = [$repoRoot.'/'.ltrim($sidecarRel, '/')];
        $base = basename($sidecarRel);
        $prelinkedDir = $repoRoot.'/prelinked/bootstrap-gen0';
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL === $sidecarRel) {
            $paths[] = $prelinkedDir.'/bin-compile-aot';
            $paths[] = $prelinkedDir.'/.m3_bin_compile_aot_blob';
        } elseif (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL === $sidecarRel) {
            $paths[] = $prelinkedDir.'/compiler_minimal_aot_blob';
        } elseif (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $paths = array_merge($paths, $this->m3EmitTuCompilerLibSidecarFallbackPaths($repoRoot));
        } elseif (is_dir($prelinkedDir)) {
            $paths[] = $prelinkedDir.'/'.$base;
            if (str_starts_with($base, '.m3_')) {
                $paths[] = $prelinkedDir.'/'.substr($base, 4);
            }
        }

        return array_values(array_unique($paths));
    }

    private function m3EmitTuTryRegisterExistingSidecarBlob(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        string $code,
        string $repoRoot
    ): bool {
        if ($this->m3EmitTuForceSidecarHostCompile()) {
            return false;
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL === $sidecarRel
            && $this->isM3HelloworldInventoryCompileDriverTarget($this->m3CompileDriverMainBlock)) {
            return false;
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            return $this->m3EmitTuTryRegisterStaleCompilerLibSidecar($path, $sidecarRel, $sentinelLogical, $code, $repoRoot);
        }
        foreach ($this->m3EmitTuExistingSidecarBlobPaths($repoRoot, $sidecarRel) as $blobPath) {
            if (!is_readable($blobPath)) {
                continue;
            }
            $aotBytes = file_get_contents($blobPath);
            if (!is_string($aotBytes) || '' === $aotBytes) {
                continue;
            }
            if ($this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)
                && \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL !== $sidecarRel) {
                continue;
            }
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                $this->context,
                $repoRoot,
                $code,
                $aotBytes,
                $sidecarRel,
                $sentinelLogical,
                true,
                $this->m3EmitTuSidecarSourcePathNorm($path)
            );

            return true;
        }

        return false;
    }

    private function cacheM3EmitTuTrivialEchoAtLinkTime(): void
    {
        if ($this->m3EmitTuSidecarsCached) {
            return;
        }
        if ($this->shouldSkipM3InventoryEmitHelperSidecarsForProbe()) {
            $this->m3EmitTuSidecarsCached = true;

            return;
        }
        $this->m3EmitTuSidecarsCached = true;
        $repoRoot = $this->m3EmitTuRuntimeRepoRoot();
        $logPrefix = Config::getenv('PHP_COMPILER_M3_EMIT_LOG_PREFIX');
        if ('helloworld_compile_smoke' === $logPrefix) {
            $minimalSidecars = $this->shouldUseM3InventoryMinimalSidecars();
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/examples/000-HelloWorld/example.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/compiler_smoke_standalone.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_SMOKE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileSmokeSentinelBlock'
            );
            if (!$minimalSidecars) {
                // Gen-3 argv driver (full revision) must be able to emit non-smoke fixtures (eg compiler unit probe)
                // without falling back to compile_smoke_m3_emit helpers (#2900, #2925).
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_UNIT_PROBE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerUnitProbeSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileDriverSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSmokeMainSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/bootstrap_loop_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BOOTSTRAP_LOOP_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::bootstrapLoopSmokeMainSentinelBlock'
                );
            }
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_minimal/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerMinimalSentinelBlock'
            );
            // Minimal inventory argv links still compile spine smoke — reuse committed/stale sidecar (#3012, #2967).
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerLibSentinelBlock',
                true
            );
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/lib/Compiler.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_PHP_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerPhpSentinelBlock'
                );
            }
            if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binCompileSentinelBlock'
                );
            }
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/vm.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binVmSentinelBlock',
                    true
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/src/cli_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::CLI_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::cliDriverSentinelBlock',
                    true
                );
            }
            // M5 vendor prelink bundles: Zend host-compile at emit-helper link (#3028, #3030, #3031).
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-cfg_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_CFG_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpCfgSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-types_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_TYPES_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpTypesSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-llvm_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_LLVM_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpLlvmSentinelBlock'
            );
        } elseif ('compile_smoke_m3_emit' === $logPrefix || $this->shouldUseM3InventoryEmitDriver()) {
            $minimalSidecars = $this->shouldUseM3InventoryMinimalSidecars();
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/examples/000-HelloWorld/example.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/compiler_smoke_standalone.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_SMOKE_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileSmokeSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_minimal/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerMinimalSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerLibSentinelBlock',
                true
            );
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_UNIT_PROBE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerUnitProbeSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/jit_unit_probe/jit_unit_probe_compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::JIT_UNIT_PROBE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::jitUnitProbeSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/jit_unit_probe/compile_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::JIT_UNIT_PROBE_COMPILE_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::jitUnitProbeCompileDriverSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/compile_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILE_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compileDriverSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/compiler_helloworld_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::helloworldSmokeMainSentinelBlock'
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/test/selfhost/bootstrap_loop_smoke/main.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BOOTSTRAP_LOOP_SMOKE_MAIN_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::bootstrapLoopSmokeMainSentinelBlock'
                );
                // M5 inventory emit via selfhost-helloworld-emit (#2666, #2681): mirror helloworld_compile_smoke branch.
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/lib/Compiler.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_PHP_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::compilerPhpSentinelBlock'
                );
            }
            if (!$this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/compile.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binCompileSentinelBlock'
                );
            }
            if (!$minimalSidecars) {
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/bin/vm.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::binVmSentinelBlock',
                    true
                );
                $this->registerM3EmitTuSidecarFromPath(
                    $repoRoot.'/src/cli_driver.php',
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::CLI_DRIVER_SIDECAR_REL,
                    'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::cliDriverSentinelBlock',
                    true
                );
            }
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/runtime_trivial_echo.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::TRIVIAL_ECHO_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::sentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-cfg_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_CFG_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpCfgSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-types_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_TYPES_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpTypesSentinelBlock'
            );
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-vendor-prelink/generated/ircmaxell-php-llvm_bundle.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::VENDOR_PHP_LLVM_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::vendorPhpLlvmSentinelBlock'
            );
        } else {
            $this->registerM3EmitTuSidecarFromPath(
                $repoRoot.'/test/bootstrap-aot/runtime_trivial_echo.php',
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::TRIVIAL_ECHO_SIDECAR_REL,
                'PHPCompiler\\JIT\\M3EmitTuTrivialEchoAot::sentinelBlock'
            );
        }
    }

    /**
     * Environment for nested bin/compile.php sidecar host-compiles (#2930).
     *
     * PHP CLI often leaves $_ENV empty while getenv() still sees exports from bootstrap scripts
     * (e.g. PHP_COMPILER_MEMORY_LIMIT=4096M). Without that, nested compiles default to 2G and OOM
     * during inventory argv link, which surfaces as exit 139.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function m3EmitSidecarHostCompileEnv(array $overrides = []): array
    {
        $base = getenv();
        if (!is_array($base)) {
            $base = is_array($_ENV) ? $_ENV : [];
        }
        $memLimit = Config::getenv('PHP_COMPILER_MEMORY_LIMIT');
        if (is_string($memLimit) && '' !== $memLimit && '-1' !== $memLimit) {
            $base['PHP_COMPILER_MEMORY_LIMIT'] = $memLimit;
        }

        return array_merge($base, $overrides);
    }

    /** Host-compile one probe source and register link-time AOT sidecar bytes (#2559, #2618). */
    private function registerM3EmitTuSidecarFromPath(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        bool $sidecarHostStubNonLiteralIncludes = false
    ): void {
        $maxDepthRaw = Config::getenv('PHP_COMPILER_M3_EMIT_SIDECAR_MAX_DEPTH');
        $maxDepth = is_string($maxDepthRaw) && '' !== $maxDepthRaw ? (int) $maxDepthRaw : 4;
        $depthRaw = Config::getenv('PHP_COMPILER_M3_EMIT_SIDECAR_DEPTH');
        $depth = is_string($depthRaw) && '' !== $depthRaw ? (int) $depthRaw : 0;
        if ($depth >= $maxDepth) {
            throw new \LogicException(
                "m3-emit-tu sidecar host-compile exceeded max depth: depth={$depth} max={$maxDepth} sidecar={$sidecarRel} source={$path}"
            );
        }
        // Prevent unbounded sidecar recursion: a sidecar host-compile runs bin/compile.php, which would
        // otherwise register/host-compile additional sidecars again (hang in bootstrap-selfhost-helloworld).
        $guard = Config::getenv('PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD');
        if ('1' === $guard || 'true' === strtolower((string) $guard)) {
            return;
        }
        if ($this->shouldSkipM3InventoryEmitDriverSelfSidecar($path)) {
            return;
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL === $sidecarRel
            && $this->shouldUseM4InventoryArgvNativeEmitRebuild()) {
            return;
        }
        if (!is_readable($path)) {
            return;
        }
        $code = file_get_contents($path);
        if (!is_string($code) || '' === $code) {
            return;
        }
        // #27426 / #27428: examples/000-HelloWorld is M5 trivial-echo shaped. Registering it as an
        // M3 content-matched sidecar makes argv-driver rebuilds prefer __compiler_copy of
        // build/.m3_helloworld_aot_blob over M5TrivialEchoNative — copy fails silently (exit 1)
        // while the C-floor shebang path handles the same source. Prefer M5 (#27429 intent).
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::HELLOWORLD_SIDECAR_REL === $sidecarRel
            && null !== \PHPCompiler\JIT\M5TrivialEchoScript::tryBuild($code, $path)
        ) {
            return;
        }
        if (null === $this->m3EmitTuTrivialEchoSource) {
            $this->m3EmitTuTrivialEchoSource = $code;
            $this->context->m3EmitTuTrivialEchoSource = $code;
            $this->context->m3EmitTuTrivialEchoPath = $path;
        }
        $repoRoot = $this->m3EmitTuRuntimeRepoRoot();
        $pathNorm = str_replace('\\', '/', $path);
        // M5 vendor bundles: Zend host-compile hits non-literal includes in php-cfg; reuse committed
        // prelinked .o at emit-helper link so native argv driver can sidecar-copy at runtime (#3028).
        if (str_contains($pathNorm, 'bootstrap-vendor-prelink/generated/')
            && preg_match('#/([^/]+)_bundle\\.php$#', $pathNorm, $vendorBundleMatch)
        ) {
            $vendorSlug = $vendorBundleMatch[1];
            $prelinkedObject = $repoRoot.'/prelinked/bootstrap-vendor/'.$vendorSlug.'.o';
            if (is_readable($prelinkedObject)) {
                $aotBytes = file_get_contents($prelinkedObject);
                if (is_string($aotBytes) && '' !== $aotBytes) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        true
                    );

                    return;
                }
            }
        }
        $repoSidecar = $repoRoot.'/'.ltrim($sidecarRel, '/');
        if (is_readable($repoSidecar)) {
            $compilerLibSidecar = \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel;
            $compilerLibStampOk = !$compilerLibSidecar
                || $this->m3EmitTuCompilerLibSidecarStampUsable(
                    $repoRoot,
                    $sidecarRel,
                    $repoRoot.'/build/.m3_compiler_lib_sidecar.sha'
                );
            if ($compilerLibStampOk || ($compilerLibSidecar && $this->m3EmitTuReuseStaleCompilerLibSidecar())) {
                $aotBytes = file_get_contents($repoSidecar);
                if (is_string($aotBytes) && '' !== $aotBytes) {
                    $pathKeyedSidecar = $compilerLibSidecar && !$compilerLibStampOk;
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        $pathKeyedSidecar,
                        $this->m3EmitTuSidecarSourcePathNorm($path)
                    );

                    return;
                }
            }
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL === $sidecarRel
            && $this->registerM3BinVmSidecarStubFallback($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
            return;
        }
        // Zend host-compile of bin/compile.php inventory argv driver SIGSEGVs — reuse committed gen-0 (#2930).
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_COMPILE_SIDECAR_REL === $sidecarRel) {
            foreach (
                [
                    $repoRoot.'/build/.m3_bin_compile_aot_blob',
                    $repoRoot.'/prelinked/bootstrap-gen0/.m3_bin_compile_aot_blob',
                    $repoRoot.'/prelinked/bootstrap-gen0/bin-compile-aot',
                ] as $prelinkedBinCompile
            ) {
                if (!is_readable($prelinkedBinCompile)) {
                    continue;
                }
                $aotBytes = file_get_contents($prelinkedBinCompile);
                if (!is_string($aotBytes) || '' === $aotBytes) {
                    continue;
                }
                $sourcePathNorm = $this->m3EmitTuSidecarSourcePathNorm($path);
                if ($this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)) {
                    // Inventory argv stubs Runtime::compile — native lint/emit needs path-keyed
                    // bin/compile.php sidecar even when gen-0 bytes embed Docker paths (#2880, #3046).
                    if (null !== $sourcePathNorm) {
                        \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                            $this->context,
                            $repoRoot,
                            $code,
                            $aotBytes,
                            $sidecarRel,
                            $sentinelLogical,
                            true,
                            $sourcePathNorm
                        );

                        return;
                    }

                    continue;
                }
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                    $this->context,
                    $repoRoot,
                    $code,
                    $aotBytes,
                    $sidecarRel,
                    $sentinelLogical,
                    true,
                    $sourcePathNorm
                );

                return;
            }
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_MINIMAL_SIDECAR_REL === $sidecarRel) {
            $prelinkedMinimal = $repoRoot.'/prelinked/bootstrap-gen0/compiler_minimal_aot_blob';
            if (is_readable($prelinkedMinimal)) {
                $aotBytes = file_get_contents($prelinkedMinimal);
                if (is_string($aotBytes) && '' !== $aotBytes && !$this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        true
                    );

                    return;
                }
            }
        }
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $prelinkedLib = $repoRoot.'/prelinked/bootstrap-gen0/compiler_lib_aot_blob';
            if (is_readable($prelinkedLib)) {
                $aotBytes = file_get_contents($prelinkedLib);
                if (is_string($aotBytes) && '' !== $aotBytes && !$this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)
                    && $this->m3EmitTuCompilerLibSidecarStampUsable(
                        $repoRoot,
                        $sidecarRel,
                        $repoRoot.'/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha'
                    )) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        true,
                        $this->m3EmitTuSidecarSourcePathNorm($path)
                    );

                    return;
                }
            }
            if ($this->m3EmitTuTryRegisterStaleCompilerLibSidecar($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
                return;
            }
        }
        if ($this->m3EmitTuTryRegisterExistingSidecarBlob($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
            return;
        }
        if (!$this->m3EmitTuForceSidecarHostCompile()
            && \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            return;
        }
        $hostCompilePath = $path;
        if (str_ends_with($pathNorm, '/bin/compile.php')) {
            // For gen-3 (argv) and other bootstrap products, compiling bin/compile.php must default to the
            // inventory emit driver (compile_driver.php) instead of the compile_smoke_m3_emit helper (#2925, #2900).
            // The helper TU is a narrow smoke path and has been observed to LLVM-segfault when used to emit
            // inventory fixtures like compiler_unit_probe_compile.php.
            $hostCompilePath = $path;
        }
        // Sidecar-only: avoid host compileEmitSmoke in emit TU LLVM module (#2540).
        // Memoize per-entrypoint+source to prevent runaway sidecar chains (#2908).
        $hostCode = @file_get_contents($hostCompilePath);
        $hostCodeHash = is_string($hostCode) && '' !== $hostCode ? substr(sha1($hostCode), 0, 16) : 'missing';
        $cacheKey = substr(sha1($hostCompilePath."\n".$sidecarRel."\n".$hostCodeHash), 0, 24);
        $cacheOut = sys_get_temp_dir().'/m3_emit_sidecar_cache_'.$cacheKey;
        $tmpOut = sys_get_temp_dir().'/m3_emit_sidecar_aot_'.getmypid().'_'.substr(md5($sidecarRel), 0, 8);
        @unlink($tmpOut);
        $compileCmd = 'php';
        $memLimit = Config::getenv('PHP_COMPILER_MEMORY_LIMIT');
        // ci_apply_llvm_memory_env pins 4096M; full-spine sidecar host-compile OOMs below 8GB (#8559).
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $memLimit = '8192M';
        }
        if (is_string($memLimit) && '' !== $memLimit && '-1' !== $memLimit) {
            $compileCmd .= ' -d memory_limit='.escapeshellarg($memLimit);
        }
        $compileCmd .= ' '.escapeshellarg($repoRoot.'/bin/compile.php')
            .' -o '.escapeshellarg($tmpOut)
            .' '.escapeshellarg($hostCompilePath);
        $compileEnv = $this->m3EmitSidecarHostCompileEnv();
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL === $sidecarRel) {
            $compileEnv['PHP_COMPILER_MEMORY_LIMIT'] = '8192M';
        }
        // Self-host skips cli/vendor includes during link; M3 compile-driver Runtime ctor native (#2600, #2633).
        $compileEnv['PHP_COMPILER_SELFHOST_AOT'] = '1';
        $compileEnv['PHP_COMPILER_M3_COMPILE_DRIVER'] = '1';
        // Recursion guard: nested bin/compile.php invocations should not spawn further sidecar host-compiles.
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD'] = '1';
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_DEPTH'] = (string) ($depth + 1);
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_MAX_DEPTH'] = (string) $maxDepth;
        if (str_ends_with($pathNorm, '/bin/compile.php')) {
            // Treat host-compiling bin/compile.php as an argv-driver build: enable the inventory emit driver
            // regardless of outer env defaults so gen-3 products don't depend on compile_smoke_m3_emit (#2925).
            $compileEnv['PHP_COMPILER_M3_COMPILE_DRIVER_MAIN'] = '1';
            $compileEnv['PHP_COMPILER_M4_BIN_COMPILE_DRIVER'] = '1';
            $compileEnv['PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER'] = '1';
            $compileEnv['BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER'] = '1';
            $compileEnv['PHP_COMPILER_EMIT_HELPER_LINK'] = '1';
            $compileEnv['PHP_COMPILER_M3_EMIT_LOG_PREFIX'] = 'helloworld_compile_smoke';
            unset($compileEnv['PHP_COMPILER_M3_EMIT_TU']);
        }
        if ($sidecarHostStubNonLiteralIncludes) {
            $compileEnv['PHP_COMPILER_M3_SIDECAR_HOST'] = '1';
        }
        $vendorObjectSidecar = str_contains($pathNorm, 'bootstrap-vendor-prelink/generated/');
        if ($vendorObjectSidecar) {
            $compileEnv['PHP_COMPILER_VENDOR_PRELINK'] = '1';
            $compileEnv['PHP_COMPILER_SELFHOST_AOT'] = '0';
            $compileEnv['PHP_COMPILER_KEEP_OBJECT_FILE'] = '1';
        }
        if (!str_ends_with($pathNorm, '/bin/compile.php')) {
            unset($compileEnv['PHP_COMPILER_EMIT_HELPER_LINK'], $compileEnv['PHP_COMPILER_M3_EMIT_TU']);
        }
        if (is_readable($cacheOut)
            && $this->m3EmitTuCompilerLibSidecarStampUsable(
                $repoRoot,
                $sidecarRel,
                $repoRoot.'/build/.m3_compiler_lib_sidecar.sha'
            )) {
            $aotBytes = file_get_contents($cacheOut);
            if (is_string($aotBytes) && '' !== $aotBytes) {
                \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                    $this->context,
                    $repoRoot,
                    $code,
                    $aotBytes,
                    $sidecarRel,
                    $sentinelLogical
                );

                return;
            }
        }
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
        $artifactPath = $tmpOut;
        if ($vendorObjectSidecar && is_readable($tmpOut.'.o')) {
            $artifactPath = $tmpOut.'.o';
        }
        if (0 !== $exit || !is_readable($artifactPath)) {
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
            @unlink($tmpOut.'.o');
            if ($this->m3EmitTuTryRegisterStaleCompilerLibSidecar($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
                return;
            }
            // Gen-2 native argv driver cannot always spawn Zend during link; reuse blobs from an
            // earlier Zend host-compile in the same workspace (#3004).
            $repoSidecar = $repoRoot.'/'.ltrim($sidecarRel, '/');
            if (is_readable($repoSidecar)
                && $this->m3EmitTuCompilerLibSidecarStampUsable(
                    $repoRoot,
                    $sidecarRel,
                    $repoRoot.'/build/.m3_compiler_lib_sidecar.sha'
                )) {
                $aotBytes = file_get_contents($repoSidecar);
                if (is_string($aotBytes) && '' !== $aotBytes) {
                    \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                        $this->context,
                        $repoRoot,
                        $code,
                        $aotBytes,
                        $sidecarRel,
                        $sentinelLogical,
                        false,
                        $this->m3EmitTuSidecarSourcePathNorm($path)
                    );

                    return;
                }
            }
            if ($this->registerM3BinVmSidecarStubFallback($path, $sidecarRel, $sentinelLogical, $code, $repoRoot)) {
                return;
            }

            return;
        }
        $aotBytes = file_get_contents($artifactPath);
        @unlink($tmpOut);
        @unlink($tmpOut.'.o');
        if (!is_string($aotBytes) || '' === $aotBytes) {
            return;
        }
        // Persist a stable copy for memoization across multiple sidecar registrations in the same link.
        // If a concurrent writer races, the content should be identical for this cacheKey.
        @file_put_contents($cacheOut, $aotBytes);
        \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
            $this->context,
            $repoRoot,
            $code,
            $aotBytes,
            $sidecarRel,
            $sentinelLogical,
            $vendorObjectSidecar,
            $this->m3EmitTuSidecarSourcePathNorm($path)
        );
    }

    /**
     * Prelinked gen-0 sidecars baked in Docker embed /compiler/build/.m3_* paths; skip on host (#3046).
     */
    private function m3EmitTuPrelinkedSidecarLooksStale(string $aotBytes): bool
    {
        return str_contains($aotBytes, '/compiler/build/.m3_')
            || str_contains($aotBytes, '/compiler/bin/compile.php');
    }

    private function m3EmitTuCompilerLibSidecarStampUsable(string $repoRoot, string $sidecarRel, string $stampPath): bool
    {
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::COMPILER_LIB_SIDECAR_REL !== $sidecarRel) {
            return true;
        }

        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::compilerLibSidecarStampMatches($repoRoot, $stampPath);
    }

    private function m3EmitTuSidecarSourcePathNorm(string $path): ?string
    {
        return \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::normalizeSidecarSourcePath($path);
    }

    /**
     * bin/vm.php honest AOT still LLVM-segfaults; register path-keyed stub sidecar (#2699, #1492).
     */
    private function registerM3BinVmSidecarStubFallback(
        string $path,
        string $sidecarRel,
        string $sentinelLogical,
        string $code,
        string $repoRoot
    ): bool {
        if (\PHPCompiler\JIT\M3EmitTuTrivialEchoAot::BIN_VM_SIDECAR_REL !== $sidecarRel) {
            return false;
        }
        $sourcePathNorm = $this->m3EmitTuSidecarSourcePathNorm($path);
        if (null === $sourcePathNorm) {
            return false;
        }
        foreach (
            [
                $repoRoot.'/prelinked/bootstrap-gen0/.m3_bin_vm_aot_blob',
                $repoRoot.'/'.ltrim($sidecarRel, '/'),
            ] as $prelinked
        ) {
            if (!is_readable($prelinked)) {
                continue;
            }
            $aotBytes = file_get_contents($prelinked);
            if (!is_string($aotBytes) || '' === $aotBytes) {
                continue;
            }
            if ($this->m3EmitTuPrelinkedSidecarLooksStale($aotBytes)) {
                continue;
            }
            \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
                $this->context,
                $repoRoot,
                $code,
                $aotBytes,
                $sidecarRel,
                $sentinelLogical,
                true,
                $sourcePathNorm
            );

            return true;
        }
        $stub = $repoRoot.'/test/bootstrap-aot/bin_vm_sidecar_stub.php';
        if (!is_readable($stub)) {
            return false;
        }
        $tmpOut = sys_get_temp_dir().'/m3_bin_vm_sidecar_stub_'.getmypid();
        @unlink($tmpOut);
        $compileCmd = 'php';
        $memLimit = Config::getenv('PHP_COMPILER_MEMORY_LIMIT');
        if (is_string($memLimit) && '' !== $memLimit && '-1' !== $memLimit) {
            $compileCmd .= ' -d memory_limit='.escapeshellarg($memLimit);
        }
        $compileCmd .= ' '.escapeshellarg($repoRoot.'/bin/compile.php')
            .' -o '.escapeshellarg($tmpOut)
            .' '.escapeshellarg($stub);
        $compileEnv = $this->m3EmitSidecarHostCompileEnv();
        $compileEnv['PHP_COMPILER_SELFHOST_AOT'] = '1';
        $compileEnv['PHP_COMPILER_M3_SIDECAR_HOST'] = '1';
        $compileEnv['PHP_COMPILER_M3_EMIT_SIDECAR_RECURSION_GUARD'] = '1';
        unset($compileEnv['PHP_COMPILER_EMIT_HELPER_LINK'], $compileEnv['PHP_COMPILER_M3_EMIT_TU']);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($compileCmd, $descriptor, $pipes, $repoRoot, $compileEnv);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if (0 !== $exit || !is_readable($tmpOut)) {
            @unlink($tmpOut);

            return false;
        }
        $aotBytes = file_get_contents($tmpOut);
        @unlink($tmpOut);
        if (!is_string($aotBytes) || '' === $aotBytes) {
            return false;
        }
        $repoSidecar = $repoRoot.'/'.ltrim($sidecarRel, '/');
        @file_put_contents($repoSidecar, $aotBytes);
        @chmod($repoSidecar, 0755);
        \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::registerLinktime(
            $this->context,
            $repoRoot,
            $code,
            $aotBytes,
            $sidecarRel,
            $sentinelLogical,
            true,
            $sourcePathNorm
        );

        return true;
    }
}

