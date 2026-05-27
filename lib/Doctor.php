<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Environment probes for local CI (issue #253).
 */
final class Doctor
{
    private static ?int $jitRuntimeProbeExit = null;

    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'ffi',
        'tokenizer',
        'mbstring',
        'dom',
        'xml',
        'xmlwriter',
        'posix',
        'phar',
    ];

    /**
     * Run all checks; print human-readable report to STDOUT.
     *
     * @param bool $jitProbe When true, run script/jit-runtime-probe.php after checks (#717).
     */
    public static function run(string $repoRoot, bool $jitProbe = false): int
    {
        $checks = self::collectChecks($repoRoot);
        $failed = 0;
        foreach ($checks as $check) {
            $icon = $check['ok'] ? 'ok' : ($check['required'] ? 'FAIL' : 'warn');
            $line = sprintf('[%s] %s: %s', $icon, $check['name'], $check['detail']);
            fwrite(STDOUT, $line."\n");
            if (!$check['ok'] && $check['required']) {
                ++$failed;
                if ('' !== $check['hint']) {
                    fwrite(STDOUT, '      → '.$check['hint']."\n");
                }
            } elseif (!$check['ok'] && '' !== $check['hint']) {
                fwrite(STDOUT, '      → '.$check['hint']."\n");
            }
        }

        if ($jitProbe) {
            $probeExit = self::runJitRuntimeProbe($repoRoot);
            if (0 !== $probeExit) {
                return $failed > 0 ? 1 : $probeExit;
            }
        }

        if ($failed > 0) {
            fwrite(STDOUT, "\n".$failed.' required check(s) failed.'."\n");
            fwrite(STDOUT, "Full local CI: ./script/ci-local.sh or ./script/docker-ci-local.sh (make test-docker)\n");
            fwrite(STDOUT, "Fast iteration (no LLVM): ./script/ci-fast.sh or phpc test --fast (make test-fast)\n");

            return 1;
        }

        fwrite(STDOUT, "\nEnvironment ready for full local CI (VM + LLVM + serve when loopback bind OK).\n");
        fwrite(STDOUT, "Run: phpc test  or  ./script/docker-ci-local.sh\n");
        fwrite(STDOUT, "Fast (VM only): phpc test --fast  or  ./script/ci-fast.sh\n");

        return 0;
    }

    /**
     * Print MiniWebApp CI gate ladder (delegates to script/miniwebapp-gates.sh — issues #472, #657).
     */
    public static function runGates(string $repoRoot, bool $noLint = false): int
    {
        $script = $repoRoot.'/script/miniwebapp-gates.sh';
        if (!is_executable($script)) {
            fwrite(STDERR, "phpc doctor --gates: {$script} missing or not executable\n");

            return 1;
        }

        $miniwebappPublic = $repoRoot.'/examples/003-MiniWebApp/public';
        if (!is_dir($miniwebappPublic)) {
            fwrite(STDERR, "phpc doctor --gates: {$miniwebappPublic} missing (#246)\n");

            return 1;
        }

        $cmd = [$script];
        if ($noLint) {
            $cmd[] = '--no-lint';
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        if (!is_resource($proc)) {
            fwrite(STDERR, "phpc doctor --gates: failed to start miniwebapp-gates.sh\n");

            return 1;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if (false !== $stdout && '' !== $stdout) {
            fwrite(STDOUT, $stdout);
        }
        if (false !== $stderr && '' !== $stderr) {
            fwrite(STDERR, $stderr);
        }

        $loopback = self::checkLoopback($repoRoot);
        if ($loopback['ok']) {
            $serveGate = getenv('MINIWEBAPP_SERVE_GATE');
            if (false !== $serveGate && '0' === $serveGate) {
                fwrite(STDOUT, "\nLoopback bind OK: unset MINIWEBAPP_SERVE_GATE=0 or export =1 for ServeTest in ci-local (#641)\n");
            }
            $webSmokeGate = getenv('MINIWEBAPP_WEB_SMOKE_GATE');
            if (false !== $webSmokeGate && '0' === $webSmokeGate) {
                fwrite(STDOUT, "Loopback bind OK: unset MINIWEBAPP_WEB_SMOKE_GATE=0 for ci-local PATH_INFO curls (#664)\n");
            }
        }

        $aotSmokeGate = getenv('EXAMPLES_AOT_SMOKE_GATE');
        if (false !== $aotSmokeGate && '0' === $aotSmokeGate) {
            fwrite(STDOUT, "LLVM ready: unset EXAMPLES_AOT_SMOKE_GATE=0 for ci-local examples-aot-smoke (#674)\n");
        }

        self::printExampleWebGatesSection($repoRoot);
        self::printSelfHostPresenterSection($repoRoot);
        self::printBootstrapInventoryLintSection($repoRoot);
        self::printSessionsWebSection($repoRoot);
        self::printFileUploadWebSection($repoRoot);
        self::printThrowsWebSection($repoRoot);
        self::printFastCGIWebSection($repoRoot);
        self::printSelfHostProbeSection($repoRoot);

        return is_int($exit) ? $exit : 1;
    }

    /**
     * Project north star — self-host gate ladder only (issues #1492, #2053).
     */
    public static function runSelfhost(string $repoRoot): int
    {
        require_once $repoRoot.'/script/bootstrap-spine-count.php';
        $counts = bootstrap_spine_counts($repoRoot);
        $spine = $counts['spine'];
        $inventory = $counts['inventory'];

        $llvmInfo = self::resolveLlvmInfo($repoRoot);
        $llvmReady = null !== $llvmInfo['dir'];
        $llvmDetail = $llvmReady
            ? 'ready at '.$llvmInfo['dir'].' ('.$llvmInfo['source'].')'
            : 'missing — link/probe steps need LLVM 9 (script/install-llvm9.sh)';

        $defaults = self::readCiDefaultsEnv($repoRoot);
        $ns2Default = $defaults['NORTH_STAR2_VERIFY_GATE'] ?? '1';
        $ns2ThrowswebDefault = $defaults['NORTH_STAR2_THROWSWEB_GATE'] ?? '1';
        $ns3Default = $defaults['NORTH_STAR3_VERIFY_GATE'] ?? '0';
        $m3HelloStrictDefault = $defaults['BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE'] ?? '0';
        $m3SmokeStrictDefault = $defaults['BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE'] ?? '0';
        $m3SmokeProbeDefault = $defaults['BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE'] ?? '1';
        $runtimeSmokeProbeDefault = $defaults['BOOTSTRAP_RUNTIME_COMPILE_SMOKE_PROBE_GATE'] ?? '1';
        $runtimeSmokeStrictDefault = $defaults['BOOTSTRAP_RUNTIME_COMPILE_SMOKE_STRICT_GATE'] ?? '0';
        $spineCountSyncDefault = $defaults['SELFHOST_SPINE_COUNT_SYNC_GATE'] ?? '1';
        $spineCoverageDefault = $defaults['SELFHOST_SPINE_COVERAGE_SYNC_GATE'] ?? '1';
        $spineDeferredDefault = $defaults['SELFHOST_SPINE_DEFERRED_SYNC_GATE'] ?? '1';
        $loopProbeDefault = $defaults['BOOTSTRAP_LOOP_PROBE_GATE'] ?? '0';
        $m4LoopProbeDefault = $defaults['BOOTSTRAP_M4_LOOP_PROBE'] ?? '0';
        $m4Gen2SyncDefault = $defaults['SELFHOST_M4_GEN2_SYNC_GATE'] ?? '1';
        $compilerDriverSmokeDefault = $defaults['COMPILER_DRIVER_SMOKE_GATE'] ?? '1';
        $compilerUnitProbeDefault = $defaults['BOOTSTRAP_COMPILER_UNIT_PROBE_GATE'] ?? '1';
        $jitUnitProbeDefault = $defaults['BOOTSTRAP_JIT_UNIT_PROBE_GATE'] ?? '0';
        $vmUnitProbeDefault = $defaults['BOOTSTRAP_VM_UNIT_PROBE_GATE'] ?? '0';
        $parserUnitProbeDefault = $defaults['BOOTSTRAP_PARSER_UNIT_PROBE_GATE'] ?? '1';
        $phptypesUnitProbeDefault = $defaults['BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE'] ?? '1';
        $testSubsetDefault = $defaults['BOOTSTRAP_TEST_SUBSET_GATE'] ?? '0';
        $testSubsetStrictDefault = $defaults['BOOTSTRAP_TEST_SUBSET_STRICT'] ?? '0';

        $inventoryScript = $repoRoot.'/script/bootstrap-inventory.php';
        $inventoryOk = is_file($inventoryScript);
        $inventoryDetail = $inventoryOk
            ? 'php script/bootstrap-inventory.php --check'
            : 'script/bootstrap-inventory.php missing';

        fwrite(STDOUT, "North star — self-host gates (#1492, #1056):\n\n");

        fwrite(STDOUT, "1. Inventory\n");
        fwrite(STDOUT, "   {$inventoryDetail}\n");
        fwrite(STDOUT, "   php script/bootstrap-spine-count.php  → {$spine}/{$inventory}\n");
        self::printInventoryTriageSection($repoRoot);
        fwrite(STDOUT, "\n");

        fwrite(STDOUT, "2. M2 spine\n");
        fwrite(STDOUT, "   SELFHOST_SPINE_COUNT_SYNC_GATE=".(self::gateEnabled('SELFHOST_SPINE_COUNT_SYNC_GATE', $spineCountSyncDefault) ? '1' : '0')." (default {$spineCountSyncDefault})\n");
        fwrite(STDOUT, "   SELFHOST_SPINE_COVERAGE_SYNC_GATE=".(self::gateEnabled('SELFHOST_SPINE_COVERAGE_SYNC_GATE', $spineCoverageDefault) ? '1' : '0')." (default {$spineCoverageDefault})\n");
        fwrite(STDOUT, "   SELFHOST_SPINE_DEFERRED_SYNC_GATE=".(self::gateEnabled('SELFHOST_SPINE_DEFERRED_SYNC_GATE', $spineDeferredDefault) ? '1' : '0')." (default {$spineDeferredDefault})\n");
        fwrite(STDOUT, "   BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke\n");
        fwrite(STDOUT, "   BOOTSTRAP_LIB_SPINE_VM_SMOKE=1 make bootstrap-selfhost-lib-spine-vm-smoke\n");
        fwrite(STDOUT, "   BOOTSTRAP_COMPILER_DRIVER_SMOKE=1 make bootstrap-selfhost-compiler-driver-smoke\n");
        fwrite(STDOUT, '   COMPILER_DRIVER_SMOKE_GATE='.(self::gateEnabled('COMPILER_DRIVER_SMOKE_GATE', $compilerDriverSmokeDefault) ? '1' : '0')." (default {$compilerDriverSmokeDefault}) — ci-local LLVM tail ([#2137](https://github.com/PurHur/php-compiler/issues/2137), [#2168](https://github.com/PurHur/php-compiler/issues/2168))\n");
        fwrite(STDOUT, "   make bootstrap-selfhost-compiler-unit-probe\n");
        fwrite(STDOUT, "   make bootstrap-selfhost-compiler-unit-probe-strict  # M3 native emit (#2618)\n");
        fwrite(STDOUT, '   BOOTSTRAP_COMPILER_UNIT_PROBE_GATE='.(self::gateEnabled('BOOTSTRAP_COMPILER_UNIT_PROBE_GATE', $compilerUnitProbeDefault) ? '1' : '0')." (default {$compilerUnitProbeDefault}) — ci-local LLVM tail ([#2216](https://github.com/PurHur/php-compiler/issues/2216), [#2221](https://github.com/PurHur/php-compiler/issues/2221))\n");
        fwrite(STDOUT, "   make bootstrap-selfhost-jit-unit-probe\n");
        fwrite(STDOUT, '   BOOTSTRAP_JIT_UNIT_PROBE_GATE='.(self::gateEnabled('BOOTSTRAP_JIT_UNIT_PROBE_GATE', $jitUnitProbeDefault) ? '1' : '0')." (default {$jitUnitProbeDefault}) — ci-local LLVM tail ([#2332](https://github.com/PurHur/php-compiler/issues/2332), [#2361](https://github.com/PurHur/php-compiler/issues/2361))\n");
        fwrite(STDOUT, "   make bootstrap-selfhost-vm-unit-probe\n");
        fwrite(STDOUT, '   BOOTSTRAP_VM_UNIT_PROBE_GATE='.(self::gateEnabled('BOOTSTRAP_VM_UNIT_PROBE_GATE', $vmUnitProbeDefault) ? '1' : '0')." (default {$vmUnitProbeDefault}) — ci-local LLVM tail ([#2354](https://github.com/PurHur/php-compiler/issues/2354), [#2368](https://github.com/PurHur/php-compiler/issues/2368))\n");
        fwrite(STDOUT, "   make bootstrap-selfhost-parser-unit-probe\n");
        fwrite(STDOUT, '   BOOTSTRAP_PARSER_UNIT_PROBE_GATE='.(self::gateEnabled('BOOTSTRAP_PARSER_UNIT_PROBE_GATE', $parserUnitProbeDefault) ? '1' : '0')." (default {$parserUnitProbeDefault}) — ci-local LLVM tail ([#2409](https://github.com/PurHur/php-compiler/issues/2409), [#2417](https://github.com/PurHur/php-compiler/issues/2417), [#2419](https://github.com/PurHur/php-compiler/issues/2419))\n");
        fwrite(STDOUT, "   make bootstrap-selfhost-types-unit-probe\n");
        fwrite(STDOUT, '   BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE='.(self::gateEnabled('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE', $phptypesUnitProbeDefault) ? '1' : '0')." (default {$phptypesUnitProbeDefault}) — ci-local LLVM tail ([#2430](https://github.com/PurHur/php-compiler/issues/2430), [#2433](https://github.com/PurHur/php-compiler/issues/2433), [#2436](https://github.com/PurHur/php-compiler/issues/2436))\n\n");

        fwrite(STDOUT, "3. M3 emit (partial vs strict)\n");
        fwrite(STDOUT, "   BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=".(self::gateEnabled('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $m3HelloStrictDefault) ? '1' : '0')." (default {$m3HelloStrictDefault}) — ci-local LLVM tail\n");
        fwrite(STDOUT, "   BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=".(self::gateEnabled('BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE', $m3SmokeProbeDefault) ? '1' : '0')." (default {$m3SmokeProbeDefault})\n");
        fwrite(STDOUT, "   BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE=".(self::gateEnabled('BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE', $m3SmokeStrictDefault) ? '1' : '0')." (default {$m3SmokeStrictDefault})\n");
        fwrite(STDOUT, "   BOOTSTRAP_RUNTIME_COMPILE_SMOKE_PROBE_GATE=".(self::gateEnabled('BOOTSTRAP_RUNTIME_COMPILE_SMOKE_PROBE_GATE', $runtimeSmokeProbeDefault) ? '1' : '0')." (default {$runtimeSmokeProbeDefault}) — ci-local lib/Runtime.php probe ([#2294](https://github.com/PurHur/php-compiler/issues/2294))\n");
        fwrite(STDOUT, "   BOOTSTRAP_RUNTIME_COMPILE_SMOKE_STRICT_GATE=".(self::gateEnabled('BOOTSTRAP_RUNTIME_COMPILE_SMOKE_STRICT_GATE', $runtimeSmokeStrictDefault) ? '1' : '0')." (default {$runtimeSmokeStrictDefault}) — opt-in strict runtime emit ([#2294](https://github.com/PurHur/php-compiler/issues/2294))\n");
        fwrite(STDOUT, "   BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1 BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1 BOOTSTRAP_M3_RUNTIME_COMPILE=1 ./script/bootstrap-selfhost-helloworld-probe.sh\n");
        fwrite(STDOUT, "   BOOTSTRAP_M3_HELLOWORLD_STRICT=1 … helloworld-probe.sh  (no Zend fallback; #1493)\n");
        fwrite(STDOUT, "   Emit TU: test/bootstrap-aot/helloworld_m3_emit_native_entry.php (#1768)\n\n");

        fwrite(STDOUT, "4. Presenter / fast CI\n");
        fwrite(STDOUT, "   NORTH_STAR2_VERIFY_GATE=".(self::gateEnabled('NORTH_STAR2_VERIFY_GATE', $ns2Default) ? '1' : '0')." (default {$ns2Default}) — ci-fast\n");
        fwrite(STDOUT, "   NORTH_STAR3_VERIFY_GATE=".(self::gateEnabled('NORTH_STAR3_VERIFY_GATE', $ns3Default) ? '1' : '0')." (default {$ns3Default}) — ci-fast opt-in ([#2396](https://github.com/PurHur/php-compiler/issues/2396))\n");
        fwrite(STDOUT, "   BOOTSTRAP_TEST_SUBSET_GATE=".(self::gateEnabled('BOOTSTRAP_TEST_SUBSET_GATE', $testSubsetDefault) ? '1' : '0')." (default {$testSubsetDefault}) — ci-fast after inventory; phpc test --bootstrap ([#2069](https://github.com/PurHur/php-compiler/issues/2069))\n");
        fwrite(STDOUT, "   BOOTSTRAP_TEST_SUBSET_STRICT=".(self::gateEnabled('BOOTSTRAP_TEST_SUBSET_STRICT', $testSubsetStrictDefault) ? '1' : '0')." (default {$testSubsetStrictDefault}) — strict M3 tail when subset gate on\n");
        if (is_executable($repoRoot.'/script/north-star2-verify.sh')) {
            fwrite(STDOUT, "   make north-star2-verify  or  ./script/north-star2-verify.sh\n");
            fwrite(STDOUT, "   NORTH_STAR2_THROWSWEB_GATE=".(self::gateEnabled('NORTH_STAR2_THROWSWEB_GATE', $ns2ThrowswebDefault) ? '1' : '0')." (default {$ns2ThrowswebDefault}) — 007 init parity + VM smoke in presenter (#2177)\n");
        }
        if (is_executable($repoRoot.'/script/north-star3-verify.sh')) {
            fwrite(STDOUT, "   make north-star3-verify  or  ./script/north-star3-verify.sh  (#2360 M3 unit probes)\n");
        }
        if (is_executable($repoRoot.'/script/north-star4-verify.sh')) {
            fwrite(STDOUT, "   make north-star4-verify  or  ./script/north-star4-verify.sh  (#2379 M4 strict loop; --dry-run-only on partial M4)\n");
        }
        fwrite(STDOUT, "   phpc test --bootstrap [--strict]\n");
        fwrite(STDOUT, "   make bootstrap-wave-check  (opt-in --with-helloworld)\n");
        fwrite(STDOUT, "   ./phpc doctor --gates | grep -i bootstrap_inventory  (#2228)\n\n");

        fwrite(STDOUT, "5. M4 loop\n");
        fwrite(STDOUT, "   BOOTSTRAP_LOOP_PROBE_GATE=".(self::gateEnabled('BOOTSTRAP_LOOP_PROBE_GATE', $loopProbeDefault) ? '1' : '0')." (default {$loopProbeDefault}) — ci-fast ./script/bootstrap-loop-probe.sh --dry-run\n");
        fwrite(STDOUT, "   BOOTSTRAP_M4_LOOP_PROBE=".(self::gateEnabled('BOOTSTRAP_M4_LOOP_PROBE', $m4LoopProbeDefault) ? '1' : '0')." (default {$m4LoopProbeDefault}) — ci-local LLVM tail after M3 strict (#2058)\n");
        fwrite(STDOUT, "   SELFHOST_M4_GEN2_SYNC_GATE=".(self::gateEnabled('SELFHOST_M4_GEN2_SYNC_GATE', $m4Gen2SyncDefault) ? '1' : '0')." (default {$m4Gen2SyncDefault}) — ci-fast check-selfhost-m4-gen2-sync.php (#2115, #2175)\n\n");

        fwrite(STDOUT, "6. LLVM\n");
        fwrite(STDOUT, "   {$llvmDetail}\n");
        if ($llvmReady) {
            fwrite(STDOUT, "   phpc doctor --jit-probe  (MCJIT smoke)\n");
        }
        fwrite(STDOUT, "\nDocs: docs/bootstrap-selfhost.md · docs/bootstrap-m5-fast-path.md · docs/self-host-target.md\n");

        return 0;
    }

    /**
     * Ranked CFG gaps from bootstrap-inventory-triage.php (#2254, #2285).
     */
    private static function printInventoryTriageSection(string $repoRoot): void
    {
        fwrite(STDOUT, "\n   Inventory triage\n");
        $script = $repoRoot.'/script/bootstrap-inventory-triage.php';
        if (!is_file($script)) {
            fwrite(STDOUT, "   pending #2254 — php script/bootstrap-inventory-triage.php\n");

            return;
        }

        $cmd = array_merge(self::phpBinary(), [$script, '--top', '3']);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        if (!is_resource($proc)) {
            fwrite(STDOUT, "   (triage subprocess failed to start)\n");

            return;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $deadline = microtime(true) + 5.0;
        $timedOut = false;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                proc_terminate($proc);
                break;
            }
            $sec = (int) floor($remaining);
            $usec = (int) (($remaining - $sec) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if (false === $ready) {
                break;
            }
            if (0 === $ready) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                continue;
            }
            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if (false !== $chunk && '' !== $chunk && $stream === $pipes[1]) {
                    $stdout .= $chunk;
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
        }
        if (!$timedOut) {
            $stdout .= (string) stream_get_contents($pipes[1]);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        if ($timedOut) {
            fwrite(STDOUT, "   (triage timed out after 5s — run: php script/bootstrap-inventory-triage.php --top 10)\n");

            return;
        }
        if ('' === trim($stdout)) {
            fwrite(STDOUT, "   (triage produced no output)\n");

            return;
        }
        foreach (preg_split('/\R/', trim($stdout)) as $line) {
            if (str_starts_with($line, 'bootstrap-inventory-triage:')) {
                fwrite(STDOUT, '   '.$line."\n");
                continue;
            }
            if ('' === trim($line)) {
                fwrite(STDOUT, "\n");
                continue;
            }
            fwrite(STDOUT, $line."\n");
        }
        fwrite(STDOUT, "   Full table: php script/bootstrap-inventory-triage.php\n");
    }

    /**
     * Example web integration gates (003-MiniWebApp ladder; issues #1845, #1857).
     */
    private static function printExampleWebGatesSection(string $repoRoot): void
    {
        $llvmInfo = self::resolveLlvmInfo($repoRoot);
        $llvmReady = null !== $llvmInfo['dir'];
        $llvmDetail = $llvmReady
            ? 'ready at '.$llvmInfo['dir'].' ('.$llvmInfo['source'].')'
            : 'missing — LLVM steps in north-star1-verify skip unless --require-llvm';

        $skipServe = getenv('PHP_COMPILER_SKIP_SERVE_TESTS');
        $serveSkipped = false !== $skipServe && '' !== $skipServe;
        $loopback = self::checkLoopback($repoRoot);
        $serveDetail = $serveSkipped
            ? 'skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)'
            : ($loopback['ok'] ? 'loopback bind OK' : 'loopback bind failed — serve / AOT web-smoke may skip');

        fwrite(STDOUT, "\nExample web integration gates (#1044 closed, #1845):\n");
        fwrite(STDOUT, "  LLVM 9: {$llvmDetail}\n");
        fwrite(STDOUT, "  Serve tests: {$serveDetail}\n");
        $nestedReturnGate = getenv('NESTED_RETURN_COMPLIANCE_GATE');
        $nestedReturnOn = false === $nestedReturnGate || '' === $nestedReturnGate || '1' === $nestedReturnGate;
        $nestedReturnDetail = $nestedReturnOn
            ? 'default on — ci-fast NestedReturn* (#1888)'
            : 'skipped (NESTED_RETURN_COMPLIANCE_GATE=0)';
        $attributesGate = getenv('ATTRIBUTES_COMPLIANCE_GATE');
        $attributesOn = false === $attributesGate || '' === $attributesGate || '1' === $attributesGate;
        $attributesDetail = $attributesOn
            ? 'default on — ci-fast Attribute* (#1904)'
            : 'skipped (ATTRIBUTES_COMPLIANCE_GATE=0)';
        $rehashGate = getenv('REHASH_COMPLIANCE_GATE');
        $rehashOn = false === $rehashGate || '' === $rehashGate || '1' === $rehashGate;
        $rehashDetail = $rehashOn
            ? 'default on — ci-fast array_rehash_string_keys (#1956)'
            : 'skipped (REHASH_COMPLIANCE_GATE=0)';
        $coalesceGate = getenv('COALESCE_COMPLIANCE_GATE');
        $coalesceOn = false === $coalesceGate || '' === $coalesceGate || '1' === $coalesceGate;
        $coalesceDetail = $coalesceOn
            ? 'default on — ci-fast Coalesce* (#1960)'
            : 'skipped (COALESCE_COMPLIANCE_GATE=0)';
        $jitVarFnGate = getenv('JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE');
        $jitVarFnOn = false === $jitVarFnGate || '' === $jitVarFnGate || '1' === $jitVarFnGate;
        $jitVarFnDetail = $jitVarFnOn
            ? 'default on — ci-fast/ci-local VariableFunction* JIT (#2060)'
            : 'skipped (JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE=0)';
        $jitServerGate = getenv('JIT_SERVER_SUPERGLOBAL_GATE');
        $jitServerOn = false === $jitServerGate || '' === $jitServerGate || '1' === $jitServerGate;
        $jitServerDetail = $jitServerOn
            ? 'default on — ci-local JitServerSuperglobal (#2257, #2275, #2292)'
            : 'skipped (JIT_SERVER_SUPERGLOBAL_GATE=0)';

        fwrite(STDOUT, "  Gates ladder     make miniwebapp-gates              stages 0–4d (#472)\n");
        fwrite(STDOUT, "  Fast CI          ./script/ci-fast.sh               VM/compliance\n");
        fwrite(STDOUT, "  Nested return    {$nestedReturnDetail}\n");
        fwrite(STDOUT, "  Attributes       {$attributesDetail}\n");
        fwrite(STDOUT, "  HashTable rehash {$rehashDetail}\n");
        fwrite(STDOUT, "  Null coalescing  {$coalesceDetail}\n");
        fwrite(STDOUT, "  JIT \$fn() calls  {$jitVarFnDetail}\n");
        fwrite(STDOUT, "  JIT \$_SERVER     {$jitServerDetail}\n");
        $defaultsWeb = self::readCiDefaultsEnv($repoRoot);
        $serveJitDefault = $defaultsWeb['SERVE_JIT_SMOKE_GATE'] ?? '0';
        $serveJitOn = self::gateEnabled('SERVE_JIT_SMOKE_GATE', $serveJitDefault);
        $serveJitDetail = $serveJitOn
            ? 'opt-in when gate=1 — examples-serve-jit-smoke (#2274)'
            : 'opt-in default 0 — SERVE_JIT_SMOKE_GATE=1 make examples-serve-jit-smoke (#2274, #1900 ci-local)';
        fwrite(STDOUT, '  Serve --jit e2e  SERVE_JIT_SMOKE_GATE='.($serveJitOn ? '1' : '0')." (default {$serveJitDefault}) — {$serveJitDetail}\n");
        $jitProjectDefault = $defaultsWeb['MINIWEBAPP_JIT_PROJECT_GATE'] ?? '0';
        $jitProjectOn = self::gateEnabled('MINIWEBAPP_JIT_PROJECT_GATE', $jitProjectDefault);
        $jitProjectDetail = $jitProjectOn
            ? 'default on when gate=1 — ci-local MiniWebAppJitProjectTest (#587, #2183)'
            : 'opt-in default 0 — MINIWEBAPP_JIT_PROJECT_GATE=1 for project JIT (#587)';
        fwrite(STDOUT, '  003 project JIT  MINIWEBAPP_JIT_PROJECT_GATE='.($jitProjectOn ? '1' : '0')." (default {$jitProjectDefault}) — {$jitProjectDetail}\n");
        fwrite(STDOUT, "  Full AOT tail    ./script/ci-local.sh --filter MiniWebAppAotExecuteTest   LLVM required\n");
        fwrite(STDOUT, "  Presenter bundle make north-star1-verify            --require-llvm / --skip-llvm-tail\n");
        fwrite(STDOUT, "  Script           ./script/north-star1-verify.sh    same as make target\n");
        fwrite(STDOUT, "  Docs             docs/miniwebapp-gates.md\n");
    }

    /**
     * Project north star — self-host presenter commands (issues #1492, #1871; bundle #1865).
     */
    private static function printSelfHostPresenterSection(string $repoRoot): void
    {
        require_once $repoRoot.'/script/bootstrap-spine-count.php';
        $counts = bootstrap_spine_counts($repoRoot);
        $spine = $counts['spine'];
        $inventory = $counts['inventory'];

        $llvmInfo = self::resolveLlvmInfo($repoRoot);
        $llvmReady = null !== $llvmInfo['dir'];
        $llvmDetail = $llvmReady
            ? 'ready at '.$llvmInfo['dir'].' ('.$llvmInfo['source'].')'
            : 'missing — M0/M2 link steps need LLVM 9';

        $m3StrictGate = getenv('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE');
        $m3StrictOn = false !== $m3StrictGate && '1' === $m3StrictGate;
        $m3StrictEnv = getenv('BOOTSTRAP_M3_HELLOWORLD_STRICT');
        $m3StrictProbe = false !== $m3StrictEnv && '1' === $m3StrictEnv;
        $m3Detail = $m3StrictOn || $m3StrictProbe
            ? 'strict probe enabled (GATE or BOOTSTRAP_M3_HELLOWORLD_STRICT=1)'
            : 'default off — export BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1 for ci-local LLVM tail (#1493)';

        $ns2Script = $repoRoot.'/script/north-star2-verify.sh';
        $ns2Make = is_executable($ns2Script);

        fwrite(STDOUT, "\nNorth star — self-host presenter (#1492, #1871):\n");
        fwrite(STDOUT, "  M2 spine: {$spine}/{$inventory} (php script/bootstrap-spine-count.php)\n");
        fwrite(STDOUT, "  LLVM 9: {$llvmDetail}\n");
        fwrite(STDOUT, "  M3 strict: {$m3Detail}\n");
        fwrite(STDOUT, "  Fast CLI         phpc test --bootstrap [--strict]   inventory + spine sync (#1961)\n");
        fwrite(STDOUT, "  Subset script    ./script/bootstrap-test-subset.sh [--strict]\n");
        fwrite(STDOUT, "  Inventory        php script/bootstrap-inventory.php --check\n");
        fwrite(STDOUT, "  Wave gate        make bootstrap-wave-check\n");
        fwrite(STDOUT, "  M0 link          ./script/bootstrap-selfhost-link.sh\n");
        fwrite(STDOUT, "  M2 spine link    BOOTSTRAP_LIB_SPINE_SMOKE=1 make bootstrap-selfhost-lib-spine-smoke\n");
        fwrite(STDOUT, "  M2 VM smoke      BOOTSTRAP_LIB_SPINE_VM_SMOKE=1 make bootstrap-selfhost-lib-spine-vm-smoke\n");
        $defaultsPresenter = self::readCiDefaultsEnv($repoRoot);
        $compilerDriverSmokeDefaultPresenter = $defaultsPresenter['COMPILER_DRIVER_SMOKE_GATE'] ?? '1';
        $compilerDriverSmokeOn = self::gateEnabled('COMPILER_DRIVER_SMOKE_GATE', $compilerDriverSmokeDefaultPresenter);
        $compilerDriverSmokeDetail = $compilerDriverSmokeOn
            ? "COMPILER_DRIVER_SMOKE_GATE=1 (default) — ci-local LLVM tail M3 driver probe (#2137, #2168)"
            : 'skipped (COMPILER_DRIVER_SMOKE_GATE=0 opt-out)';
        fwrite(STDOUT, "  M3 driver smoke  BOOTSTRAP_COMPILER_DRIVER_SMOKE=1 make bootstrap-selfhost-compiler-driver-smoke\n");
        fwrite(STDOUT, "                   {$compilerDriverSmokeDetail}\n");
        $compilerUnitProbeDefaultPresenter = $defaultsPresenter['BOOTSTRAP_COMPILER_UNIT_PROBE_GATE'] ?? '1';
        $compilerUnitProbeOn = self::gateEnabled('BOOTSTRAP_COMPILER_UNIT_PROBE_GATE', $compilerUnitProbeDefaultPresenter);
        $compilerUnitProbeDetail = $compilerUnitProbeOn
            ? 'BOOTSTRAP_COMPILER_UNIT_PROBE_GATE=1 — ci-local LLVM tail M3 Compiler unit probe (#2216, #2221)'
            : 'opt-out BOOTSTRAP_COMPILER_UNIT_PROBE_GATE=0 to skip lib/Compiler.php native link (#2216)';
        fwrite(STDOUT, "  M3 compiler probe make bootstrap-selfhost-compiler-unit-probe\n");
        fwrite(STDOUT, "                   {$compilerUnitProbeDetail}\n");
        $jitUnitProbeDefaultPresenter = $defaultsPresenter['BOOTSTRAP_JIT_UNIT_PROBE_GATE'] ?? '0';
        $jitUnitProbeOn = self::gateEnabled('BOOTSTRAP_JIT_UNIT_PROBE_GATE', $jitUnitProbeDefaultPresenter);
        $jitUnitProbeDetail = $jitUnitProbeOn
            ? 'BOOTSTRAP_JIT_UNIT_PROBE_GATE=1 — ci-local LLVM tail M3 JIT unit probe (#2332, #2361)'
            : 'opt-in BOOTSTRAP_JIT_UNIT_PROBE_GATE=1 for lib/JIT.php native link (#2332, #2361)';
        fwrite(STDOUT, "  M3 JIT probe     make bootstrap-selfhost-jit-unit-probe\n");
        fwrite(STDOUT, "                   {$jitUnitProbeDetail}\n");
        $vmUnitProbeDefaultPresenter = $defaultsPresenter['BOOTSTRAP_VM_UNIT_PROBE_GATE'] ?? '0';
        $vmUnitProbeOn = self::gateEnabled('BOOTSTRAP_VM_UNIT_PROBE_GATE', $vmUnitProbeDefaultPresenter);
        $vmUnitProbeDetail = $vmUnitProbeOn
            ? 'BOOTSTRAP_VM_UNIT_PROBE_GATE=1 — ci-local LLVM tail M3 VM unit probe (#2354, #2368)'
            : 'opt-in BOOTSTRAP_VM_UNIT_PROBE_GATE=1 for lib/VM.php native link (#2354, #2368)';
        fwrite(STDOUT, "  M3 VM probe      make bootstrap-selfhost-vm-unit-probe\n");
        fwrite(STDOUT, "                   {$vmUnitProbeDetail}\n");
        $parserUnitProbeDefaultPresenter = $defaultsPresenter['BOOTSTRAP_PARSER_UNIT_PROBE_GATE'] ?? '1';
        $parserUnitProbeOn = self::gateEnabled('BOOTSTRAP_PARSER_UNIT_PROBE_GATE', $parserUnitProbeDefaultPresenter);
        $parserUnitProbeDetail = $parserUnitProbeOn
            ? 'BOOTSTRAP_PARSER_UNIT_PROBE_GATE=1 — ci-local LLVM tail M3 parser unit probe (#2409, #2417, #2419)'
            : 'opt-out BOOTSTRAP_PARSER_UNIT_PROBE_GATE=0 to skip CFG parse front-end native link (#2409, #2419)';
        fwrite(STDOUT, "  M3 parser probe  make bootstrap-selfhost-parser-unit-probe\n");
        fwrite(STDOUT, "                   {$parserUnitProbeDetail}\n");
        $phptypesUnitProbeDefaultPresenter = $defaultsPresenter['BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE'] ?? '1';
        $phptypesUnitProbeOn = self::gateEnabled('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE', $phptypesUnitProbeDefaultPresenter);
        $phptypesUnitProbeDetail = $phptypesUnitProbeOn
            ? 'BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE=1 — ci-local LLVM tail M3 PHPTypes unit probe (#2430, #2433, #2436)'
            : 'opt-out BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE=0 to skip PHPTypes Type constants native link (#2430, #2436)';
        fwrite(STDOUT, "  M3 types probe   make bootstrap-selfhost-types-unit-probe\n");
        fwrite(STDOUT, "                   {$phptypesUnitProbeDetail}\n");
        $loopProbeGate = getenv('BOOTSTRAP_LOOP_PROBE_GATE');
        $loopProbeOn = false !== $loopProbeGate && '1' === $loopProbeGate;
        $m4LoopProbeGate = getenv('BOOTSTRAP_M4_LOOP_PROBE');
        $m4LoopProbeOn = false !== $m4LoopProbeGate && '1' === $m4LoopProbeGate;
        $loopProbeDetail = $loopProbeOn
            ? 'BOOTSTRAP_LOOP_PROBE_GATE=1 — ci-fast dry-run (#1929)'
            : 'opt-in BOOTSTRAP_LOOP_PROBE_GATE=1 for M4 dry-run in ci-fast (#1929)';
        $m4LoopProbeDetail = $m4LoopProbeOn
            ? 'BOOTSTRAP_M4_LOOP_PROBE=1 — ci-local LLVM tail after M3 strict (#2058)'
            : 'opt-in BOOTSTRAP_M4_LOOP_PROBE=1 for M4 dry-run in ci-local (#2058)';
        fwrite(STDOUT, "  M4 loop dry-run  {$loopProbeDetail}\n");
        fwrite(STDOUT, "  M4 ci-local      {$m4LoopProbeDetail}\n");
        $ns2CiGate = getenv('NORTH_STAR2_VERIFY_GATE');
        $ns2CiOn = false !== $ns2CiGate && '1' === $ns2CiGate;
        $ns2CiDetail = $ns2CiOn
            ? 'NORTH_STAR2_VERIFY_GATE=1 (default) — ci-fast runs presenter (#1928, #2051)'
            : 'opt-out NORTH_STAR2_VERIFY_GATE=0 skips presenter in ci-fast (#1928)';
        $ns3CiGate = getenv('NORTH_STAR3_VERIFY_GATE');
        $ns3CiOn = false !== $ns3CiGate && '1' === $ns3CiGate;
        $ns3CiDetail = $ns3CiOn
            ? 'NORTH_STAR3_VERIFY_GATE=1 — ci-fast runs make north-star3-verify (#2396)'
            : 'opt-in NORTH_STAR3_VERIFY_GATE=1 for M3 unit probes in ci-fast (#2396)';
        $defaults = self::readCiDefaultsEnv($repoRoot);
        $ns2ThrowswebDefault = $defaults['NORTH_STAR2_THROWSWEB_GATE'] ?? '1';
        $ns2ThrowswebOn = self::gateEnabled('NORTH_STAR2_THROWSWEB_GATE', $ns2ThrowswebDefault);
        $ns2ThrowswebDetail = $ns2ThrowswebOn
            ? 'NORTH_STAR2_THROWSWEB_GATE=1 (default) — init parity + make examples-throws-smoke (#2177)'
            : 'opt-out NORTH_STAR2_THROWSWEB_GATE=0 skips 007 ladder in north-star2-verify (#2177)';
        $subsetGate = getenv('BOOTSTRAP_TEST_SUBSET_GATE');
        $subsetOn = false !== $subsetGate && '1' === $subsetGate;
        $subsetDetail = $subsetOn
            ? 'BOOTSTRAP_TEST_SUBSET_GATE=1 — ci-fast runs bootstrap-test-subset (#2069)'
            : 'opt-in BOOTSTRAP_TEST_SUBSET_GATE=1 for phpc test --bootstrap in ci-fast (#2069)';
        $selfhostProbeExample = $repoRoot.'/examples/008-SelfHostProbe/example.php';
        if (is_file($selfhostProbeExample)) {
            fwrite(STDOUT, "  008 VM smoke       make examples-selfhostprobe-smoke (#2240)\n");
        }
        if ($ns2Make) {
            fwrite(STDOUT, "  Presenter bundle make north-star2-verify            --require-llvm / --skip-llvm-tail / --skip-throwsweb\n");
            fwrite(STDOUT, "  Script           ./script/north-star2-verify.sh    same as make target\n");
            fwrite(STDOUT, "  007 ladder       {$ns2ThrowswebDetail}\n");
        } else {
            fwrite(STDOUT, "  Presenter bundle make north-star2-verify            script missing in tree\n");
        }
        $ns3Script = $repoRoot.'/script/north-star3-verify.sh';
        if (is_executable($ns3Script)) {
            fwrite(STDOUT, "  M3 unit probes   make north-star3-verify            008 + compiler/JIT/VM/parser/PHPTypes (#2360, #2434)\n");
            fwrite(STDOUT, "  Script           ./script/north-star3-verify.sh    --require-llvm when probes must run\n");
        }
        $ns4Script = $repoRoot.'/script/north-star4-verify.sh';
        if (is_executable($ns4Script)) {
            fwrite(STDOUT, "  M4 strict loop   make north-star4-verify            inventory + M3 strict + gen-1 link + loop probe (#2379)\n");
            fwrite(STDOUT, "  Script           ./script/north-star4-verify.sh    --dry-run-only / --strict / --require-llvm\n");
        }
        fwrite(STDOUT, "  Fast CI hook     {$ns2CiDetail}\n");
        fwrite(STDOUT, "  M3 ci-fast       {$ns3CiDetail}\n");
        fwrite(STDOUT, "  Bootstrap subset {$subsetDetail}\n");
        fwrite(STDOUT, "  Docs             docs/bootstrap-selfhost.md · docs/self-host-target.md (#1492)\n");
    }

    /**
     * Bootstrap inventory static lint + snapshot sync (issues #2208, #2210, #2228).
     */
    private static function printBootstrapInventoryLintSection(string $repoRoot): void
    {
        $lintBin = $repoRoot.'/bin/lint.php';
        if (!is_readable($lintBin)) {
            return;
        }

        $syncScript = $repoRoot.'/script/check-bootstrap-inventory-lint-sync.php';
        $snapshotScript = $repoRoot.'/script/bootstrap-inventory-lint-snapshot.php';
        $snapshotPath = $repoRoot.'/docs/bootstrap-inventory-lint-snapshot.json';
        $hasSync = is_readable($syncScript);
        $hasSnapshot = is_readable($snapshotPath);

        $defaults = self::readCiDefaultsEnv($repoRoot);
        $syncDefault = $defaults['BOOTSTRAP_INVENTORY_LINT_SYNC_GATE'] ?? '0';
        $syncOn = self::gateEnabled('BOOTSTRAP_INVENTORY_LINT_SYNC_GATE', $syncDefault);

        require_once $repoRoot.'/script/bootstrap-spine-count.php';
        $counts = bootstrap_spine_counts($repoRoot);
        $inventoryTotal = $counts['inventory'];

        $snapshotSummary = self::bootstrapInventoryLintSnapshotSummary($repoRoot);

        fwrite(STDOUT, "\nBootstrap inventory lint (#2228, #2208, #2210):\n");
        fwrite(STDOUT, "  Scope: {$inventoryTotal} file(s) on bin/vm.php path (same as inventory --check)\n");
        fwrite(STDOUT, "  Defaults: script/ci-defaults.env\n\n");

        fwrite(STDOUT, "  Sweep: ./phpc lint --bootstrap-inventory\n");
        fwrite(STDOUT, "         ./phpc lint --bootstrap-inventory --check  (exit 1 on unsupported syntax)\n");
        fwrite(STDOUT, "         ./phpc lint --bootstrap-inventory --json   (machine report)\n\n");

        if ('' !== $snapshotSummary) {
            fwrite(STDOUT, "  Snapshot: {$snapshotSummary}\n");
            fwrite(STDOUT, "            docs/bootstrap-inventory-lint-snapshot.json\n\n");
        } elseif ($hasSnapshot) {
            fwrite(STDOUT, "  Snapshot: docs/bootstrap-inventory-lint-snapshot.json present (unreadable summary)\n\n");
        }

        $syncIcon = $syncOn ? '✅' : '⬜';
        $syncNote = $syncOn
            ? 'ci-fast inventory checks when gate=1 (#2210)'
            : 'opt-in default 0 — set BOOTSTRAP_INVENTORY_LINT_SYNC_GATE=1 in ci-fast';
        fwrite(STDOUT, "  [{$syncIcon}] BOOTSTRAP_INVENTORY_LINT_SYNC_GATE default {$syncDefault} ({$syncNote})\n");
        if ($hasSync) {
            fwrite(STDOUT, "      Run: php script/check-bootstrap-inventory-lint-sync.php\n");
        } else {
            fwrite(STDOUT, "      Run: script/check-bootstrap-inventory-lint-sync.php missing\n");
        }
        if (is_readable($snapshotScript)) {
            fwrite(STDOUT, "      Regen: php script/bootstrap-inventory-lint-snapshot.php --write\n");
        }
        $triageSyncScript = $repoRoot.'/script/check-bootstrap-inventory-triage-sync.php';
        $triageSnapshotPath = $repoRoot.'/docs/bootstrap-inventory-triage-top50.json';
        $triageSyncDefault = $defaults['BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE'] ?? '0';
        $triageSyncOn = self::gateEnabled('BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE', $triageSyncDefault);
        $triageIcon = $triageSyncOn ? '✅' : '⬜';
        $triageNote = $triageSyncOn
            ? 'ci-fast inventory checks when gate=1 (#2265)'
            : 'opt-in default 0 — set BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE=1 in ci-fast';
        fwrite(STDOUT, "\n  Triage top-50 ([#2254](https://github.com/PurHur/php-compiler/issues/2254), [#2265](https://github.com/PurHur/php-compiler/issues/2265)):\n");
        fwrite(STDOUT, "  [{$triageIcon}] BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE default {$triageSyncDefault} ({$triageNote})\n");
        if (is_readable($triageSyncScript)) {
            fwrite(STDOUT, "      Run: php script/check-bootstrap-inventory-triage-sync.php\n");
        }
        if (is_readable($triageSnapshotPath)) {
            fwrite(STDOUT, "      Snapshot: docs/bootstrap-inventory-triage-top50.json\n");
        }
        fwrite(STDOUT, "      Regen: php script/bootstrap-inventory-triage.php --json --top 50 > docs/bootstrap-inventory-triage-top50.json\n");

        $stdlibJitSyncScript = $repoRoot.'/script/check-stdlib-jit-deferred-sync.php';
        $stdlibJitSyncDefault = $defaults['STDLIB_JIT_DEFERRED_SYNC_GATE'] ?? '0';
        $stdlibJitSyncOn = self::gateEnabled('STDLIB_JIT_DEFERRED_SYNC_GATE', $stdlibJitSyncDefault);
        $stdlibJitIcon = $stdlibJitSyncOn ? '✅' : '⬜';
        $stdlibJitNote = $stdlibJitSyncOn
            ? 'ci-fast inventory checks when gate=1 (#2465)'
            : 'default 1 — set STDLIB_JIT_DEFERRED_SYNC_GATE=0 to opt out (#2476)';
        fwrite(STDOUT, "\n  Stdlib JIT deferrals ([#2441](https://github.com/PurHur/php-compiler/issues/2441), [#2465](https://github.com/PurHur/php-compiler/issues/2465)):\n");
        fwrite(STDOUT, "  [{$stdlibJitIcon}] STDLIB_JIT_DEFERRED_SYNC_GATE default {$stdlibJitSyncDefault} ({$stdlibJitNote})\n");
        if (is_readable($stdlibJitSyncScript)) {
            fwrite(STDOUT, "      Run: php script/check-stdlib-jit-deferred-sync.php\n");
        }
        fwrite(STDOUT, "      Audit: php script/audit-stdlib-jit.php → docs/stdlib-jit-audit.md\n");
        fwrite(STDOUT, "      Allowlist: script/stdlib-jit-deferred-lib.php\n");

        fwrite(STDOUT, "  Probe: ./phpc doctor --gates | grep -i bootstrap_inventory\n");
        fwrite(STDOUT, "  Docs: docs/bootstrap-selfhost.md · docs/local-ci-matrix.md\n");
    }

    /** Human-readable snapshot summary, or empty string when unavailable. */
    private static function bootstrapInventoryLintSnapshotSummary(string $repoRoot): string
    {
        $snapshotPath = $repoRoot.'/docs/bootstrap-inventory-lint-snapshot.json';
        if (!is_readable($snapshotPath)) {
            return '';
        }

        $lib = $repoRoot.'/script/bootstrap-inventory-lint-sync-lib.php';
        if (!is_readable($lib)) {
            return '';
        }

        require_once $lib;

        try {
            $snapshot = bootstrap_inventory_lint_read_snapshot($snapshotPath);
        } catch (\Throwable $e) {
            return '';
        }

        $files = $snapshot['files'];
        $fileCount = count($files);
        $kindCount = 0;
        foreach ($files as $kinds) {
            $kindCount += count($kinds);
        }

        if (0 === $fileCount) {
            return '0 file(s) with unsupported syntax in committed snapshot';
        }

        return "{$fileCount} file(s), {$kindCount} unsupported kind(s) in committed snapshot";
    }

    /**
     * 005-SessionsWeb gate ladder (issues #1881, #1903, #1969).
     */
    private static function printSessionsWebSection(string $repoRoot): void
    {
        $exampleDir = $repoRoot.'/examples/005-SessionsWeb';
        if (!is_dir($exampleDir)) {
            return;
        }

        $defaults = self::readCiDefaultsEnv($repoRoot);
        $smokeDefault = $defaults['SESSIONS_WEB_SMOKE_GATE'] ?? '1';
        $serveAotDefault = $defaults['SESSIONS_WEB_SERVE_AOT_SMOKE_GATE'] ?? '0';
        $linkDefault = $defaults['SESSIONS_WEB_AOT_LINK_GATE'] ?? '1';
        $aotDefault = $defaults['SESSIONS_WEB_AOT_SMOKE_GATE'] ?? '0';
        $deployDefault = $defaults['SESSIONS_WEB_DEPLOY_SMOKE_GATE'] ?? '0';

        $smokeOn = self::gateEnabled('SESSIONS_WEB_SMOKE_GATE', $smokeDefault);
        $serveAotOn = self::gateEnabled('SESSIONS_WEB_SERVE_AOT_SMOKE_GATE', $serveAotDefault);
        $linkOn = self::gateEnabled('SESSIONS_WEB_AOT_LINK_GATE', $linkDefault);
        $aotOn = self::gateEnabled('SESSIONS_WEB_AOT_SMOKE_GATE', $aotDefault);
        $deployOn = self::gateEnabled('SESSIONS_WEB_DEPLOY_SMOKE_GATE', $deployDefault);

        $llvmInfo = self::resolveLlvmInfo($repoRoot);
        $llvmReady = null !== $llvmInfo['dir'];
        $llvmDetail = $llvmReady
            ? 'LLVM ready at '.$llvmInfo['dir']
            : 'LLVM missing — AOT + deploy rows need libLLVM-9.so.1';

        $hasExample = is_file($exampleDir.'/example.php');
        $hasManifest = is_file($exampleDir.'/phpc.json');

        fwrite(STDOUT, "\n005-SessionsWeb CI gates (#1969, ladder #1903):\n");
        fwrite(STDOUT, "  Tree: examples/005-SessionsWeb\n");
        fwrite(STDOUT, "  {$llvmDetail}\n");
        fwrite(STDOUT, "  Defaults: script/ci-defaults.env\n\n");

        if ($hasExample && $hasManifest) {
            fwrite(STDOUT, "  [✅] example.php + phpc.json present\n");
        } else {
            fwrite(STDOUT, "  [⬜] example tree incomplete (expected example.php + phpc.json)\n");
        }
        fwrite(STDOUT, "  Lint: ./phpc lint examples/005-SessionsWeb/example.php\n\n");

        self::printSessionsWebGateRow(
            1,
            'VM session flash',
            'SESSIONS_WEB_SMOKE_GATE',
            $smokeDefault,
            $smokeOn,
            false,
            'make examples-sessions-smoke · ci-fast (#1887)',
            '#1887'
        );
        $serveAotStatus = $serveAotOn && $llvmReady ? '✅' : '📋';
        $serveAotNote = $llvmReady
            ? ($serveAotOn ? '#2333 ✅' : 'opt-in — phpc serve --aot session flash (#2333)')
            : 'LLVM required; #2333 when gate=1';
        fwrite(STDOUT, "  [{$serveAotStatus}] Stage 2b AOT serve — SESSIONS_WEB_SERVE_AOT_SMOKE_GATE default {$serveAotDefault} ({$serveAotNote})\n");
        fwrite(STDOUT, "      Run:     SESSIONS_WEB_SERVE_AOT_SMOKE_GATE=1 ./script/examples-web-smoke.sh --sessions-only --aot\n");
        self::printSessionsWebGateRow(
            2,
            'AOT link',
            'SESSIONS_WEB_AOT_LINK_GATE',
            $linkDefault,
            $linkOn,
            true,
            './script/ci-local.sh --filter test005SessionsWebAotLink (#1946)',
            '#1946'
        );
        $aotStatus = $aotOn && $llvmReady ? '✅' : '📋';
        $aotExecuteNote = $llvmReady
            ? ($aotOn ? '#1891 ✅' : '#1891 ✅ · opt-in until #1923 (#1921)')
            : 'LLVM required; #1891 ✅ when gate=1';
        fwrite(STDOUT, "  [{$aotStatus}] Stage 3 AOT execute — SESSIONS_WEB_AOT_SMOKE_GATE default {$aotDefault} ({$aotExecuteNote})\n");
        fwrite(STDOUT, "      PHPUnit: ./script/ci-local.sh --filter SessionsWebAotExecuteTest\n");
        fwrite(STDOUT, "      Shell:   SESSIONS_WEB_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=005 ./script/examples-aot-smoke.sh\n");
        $deployStatus = $deployOn && $llvmReady ? '✅' : '📋';
        $deployNote = $deployOn
            ? '#1893 ✅ · ci-local when gate=1 (#1967)'
            : 'opt-in default 0 — #1893 · #1962';
        fwrite(STDOUT, "  [{$deployStatus}] Stage 4 Deploy CGI — SESSIONS_WEB_DEPLOY_SMOKE_GATE default {$deployDefault} ({$deployNote})\n");
        fwrite(STDOUT, "      Run:     SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all\n");
        fwrite(STDOUT, "      Or:      SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 005\n");
        fwrite(STDOUT, "      Ladder:  make deploy-smoke-all (skips 005/006 with hints when gates=0; #2077)\n");

        $rebuild005Default = $defaults['REBUILD_EXAMPLES_005_SYNC_GATE'] ?? '1';
        $rebuild005On = self::gateEnabled('REBUILD_EXAMPLES_005_SYNC_GATE', $rebuild005Default);
        $rebuild005Icon = $rebuild005On ? '✅' : '⬜';
        fwrite(STDOUT, "\n  Doc sync (ci-fast inventory):\n");
        fwrite(STDOUT, "  [{$rebuild005Icon}] examples/README 005 benchmark row — REBUILD_EXAMPLES_005_SYNC_GATE default {$rebuild005Default} (#1953)\n");
        fwrite(STDOUT, "      Run: php script/check-rebuild-examples-005-row.php · ci-fast (#1930)\n");

        $initProfileLive = \PHPCompiler\Cli\PhpcInit::isKnownProfile('sessionsweb');
        $initTemplate = is_file($repoRoot.'/templates/init-sessionsweb/example.php');
        fwrite(STDOUT, "\n  Related:\n");
        if ($initProfileLive) {
            fwrite(STDOUT, "  [✅] phpc init --profile sessionsweb (#1886)\n");
        } elseif ($initTemplate) {
            fwrite(STDOUT, "  [📋] phpc init --profile sessionsweb — template ready; CLI profile pending #1886\n");
        } else {
            fwrite(STDOUT, "  [📋] phpc init --profile sessionsweb — #1886\n");
        }
        fwrite(STDOUT, "  Docs: examples/005-SessionsWeb/README.md · docs/local-ci-matrix.md\n");
    }

    /**
     * 006-FileUploadWeb gate ladder (issues #1999, #2010).
     */
    private static function printFileUploadWebSection(string $repoRoot): void
    {
        $exampleDir = $repoRoot.'/examples/006-FileUploadWeb';
        if (!is_dir($exampleDir)) {
            return;
        }

        $defaults = self::readCiDefaultsEnv($repoRoot);
        $smokeDefault = $defaults['FILE_UPLOAD_WEB_SMOKE_GATE'] ?? '1';
        $serveAotDefault = $defaults['FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE'] ?? '0';
        $linkDefault = $defaults['FILE_UPLOAD_WEB_AOT_LINK_GATE'] ?? '1';
        $aotDefault = $defaults['FILE_UPLOAD_WEB_AOT_SMOKE_GATE'] ?? '1';
        $deployDefault = $defaults['FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE'] ?? '0';

        $smokeOn = self::gateEnabled('FILE_UPLOAD_WEB_SMOKE_GATE', $smokeDefault);
        $serveAotOn = self::gateEnabled('FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE', $serveAotDefault);
        $linkOn = self::gateEnabled('FILE_UPLOAD_WEB_AOT_LINK_GATE', $linkDefault);
        $aotOn = self::gateEnabled('FILE_UPLOAD_WEB_AOT_SMOKE_GATE', $aotDefault);
        $deployOn = self::gateEnabled('FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE', $deployDefault);

        $llvmInfo = self::resolveLlvmInfo($repoRoot);
        $llvmReady = null !== $llvmInfo['dir'];
        $llvmDetail = $llvmReady
            ? 'LLVM ready at '.$llvmInfo['dir']
            : 'LLVM missing — AOT rows need libLLVM-9.so.1';

        $hasExample = is_file($exampleDir.'/example.php');
        $hasManifest = is_file($exampleDir.'/phpc.json');

        fwrite(STDOUT, "\n006-FileUploadWeb CI gates (#2010, ladder #1999–#2012):\n");
        fwrite(STDOUT, "  Tree: examples/006-FileUploadWeb\n");
        fwrite(STDOUT, "  {$llvmDetail}\n");
        fwrite(STDOUT, "  Defaults: script/ci-defaults.env\n\n");

        if ($hasExample && $hasManifest) {
            fwrite(STDOUT, "  [✅] example.php + phpc.json present\n");
        } else {
            fwrite(STDOUT, "  [⬜] example tree incomplete (expected example.php + phpc.json)\n");
        }
        fwrite(STDOUT, "  Lint: ./phpc lint examples/006-FileUploadWeb/example.php\n\n");

        self::printSessionsWebGateRow(
            1,
            'VM multipart',
            'FILE_UPLOAD_WEB_SMOKE_GATE',
            $smokeDefault,
            $smokeOn,
            false,
            './script/examples-web-smoke.sh --fileupload-only · ci-fast (#2009)',
            '#2009'
        );
        $serveAotStatus = $serveAotOn && $llvmReady ? '✅' : '📋';
        $serveAotNote = $llvmReady
            ? ($serveAotOn ? '#2333 ✅' : 'opt-in — phpc serve --aot multipart POST (#2333)')
            : 'LLVM required; #2333 when gate=1';
        fwrite(STDOUT, "  [{$serveAotStatus}] Stage 2b AOT serve — FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE default {$serveAotDefault} ({$serveAotNote})\n");
        fwrite(STDOUT, "      Run:     FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE=1 ./script/examples-web-smoke.sh --fileupload-only --aot\n");
        self::printSessionsWebGateRow(
            2,
            'AOT link',
            'FILE_UPLOAD_WEB_AOT_LINK_GATE',
            $linkDefault,
            $linkOn,
            true,
            './script/ci-local.sh --filter test006FileUploadWebAotLink (#2011)',
            '#2011'
        );
        $aotStatus = $aotOn && $llvmReady ? '✅' : '📋';
        $aotExecuteNote = $llvmReady
            ? ($aotOn ? '#1999 ✅' : '#1999 ✅ · set gate=1 (#2012)')
            : 'LLVM required; #1999 ✅ when gate=1';
        fwrite(STDOUT, "  [{$aotStatus}] Stage 3 AOT execute — FILE_UPLOAD_WEB_AOT_SMOKE_GATE default {$aotDefault} ({$aotExecuteNote})\n");
        fwrite(STDOUT, "      PHPUnit: ./script/ci-local.sh --filter FileUploadWebAotExecuteTest\n");
        fwrite(STDOUT, "      Shell:   EXAMPLES_AOT_SMOKE_ONLY=006 ./script/examples-aot-smoke.sh\n");
        $deployStatus = $deployOn && $llvmReady ? '✅' : '📋';
        $deployNote = $deployOn
            ? '#2028 ✅ · ci-local when gate=1 (#2042)'
            : 'opt-in default 0 — #2028 · #2038';
        fwrite(STDOUT, "  [{$deployStatus}] Stage 4 Deploy CGI — FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE default {$deployDefault} ({$deployNote})\n");
        fwrite(STDOUT, "      Run:     FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all\n");
        fwrite(STDOUT, "      Or:      make examples-fileupload-deploy-smoke\n");
        fwrite(STDOUT, "      Ladder:  make deploy-smoke-all (skips 005/006 with hints when gates=0; #2077)\n");

        $rebuild006Default = $defaults['REBUILD_EXAMPLES_006_SYNC_GATE'] ?? '1';
        $rebuild006On = self::gateEnabled('REBUILD_EXAMPLES_006_SYNC_GATE', $rebuild006Default);
        $rebuild006Icon = $rebuild006On ? '✅' : '⬜';
        $capabilities006Default = $defaults['CAPABILITIES_006_SYNC_GATE'] ?? '1';
        $capabilities006On = self::gateEnabled('CAPABILITIES_006_SYNC_GATE', $capabilities006Default);
        $capabilities006Icon = $capabilities006On ? '✅' : '⬜';
        $rootReadme006Default = $defaults['ROOT_README_006_SYNC_GATE'] ?? '1';
        $rootReadme006On = self::gateEnabled('ROOT_README_006_SYNC_GATE', $rootReadme006Default);
        $rootReadme006Icon = $rootReadme006On ? '✅' : '⬜';
        fwrite(STDOUT, "\n  Doc sync (ci-fast inventory):\n");
        fwrite(STDOUT, "  [{$rebuild006Icon}] examples/README 006 run matrix — REBUILD_EXAMPLES_006_SYNC_GATE default {$rebuild006Default} (#2018, #2052)\n");
        fwrite(STDOUT, "      Run: php script/check-rebuild-examples-006-row.php · ci-fast when gate=1\n");
        fwrite(STDOUT, "  [{$capabilities006Icon}] capabilities 006 rows — CAPABILITIES_006_SYNC_GATE default {$capabilities006Default} (#2019, #2068)\n");
        fwrite(STDOUT, "      Run: php script/check-capabilities-fileuploadweb-sync.php · ci-fast when gate=1\n");
        fwrite(STDOUT, "  [{$rootReadme006Icon}] README 006 stale phrases — ROOT_README_006_SYNC_GATE default {$rootReadme006Default} (#2017, #2052)\n");
        fwrite(STDOUT, "      Run: ROOT_README_006_SYNC_GATE=1 php script/check-root-readme-sync.php · ci-fast when gate=1\n");

        $initProfileLive = \PHPCompiler\Cli\PhpcInit::isKnownProfile('fileupload');
        $initTemplate = is_file($repoRoot.'/templates/init-fileupload/example.php');
        fwrite(STDOUT, "\n  Related:\n");
        if ($initProfileLive) {
            fwrite(STDOUT, "  [✅] phpc init --profile fileupload (#2004)\n");
        } elseif ($initTemplate) {
            fwrite(STDOUT, "  [📋] phpc init --profile fileupload — template ready; CLI profile pending #2004\n");
        } else {
            fwrite(STDOUT, "  [📋] phpc init --profile fileupload — #2004\n");
        }
        fwrite(STDOUT, "  Docs: examples/006-FileUploadWeb/README.md · docs/local-ci-matrix.md (#2010)\n");
    }

    /**
     * 007-ThrowsWeb gate ladder (issues #2076, #2102).
     */
    private static function printThrowsWebSection(string $repoRoot): void
    {
        $exampleDir = $repoRoot.'/examples/007-ThrowsWeb';
        if (!is_dir($exampleDir)) {
            return;
        }

        $defaults = self::readCiDefaultsEnv($repoRoot);
        $smokeDefault = $defaults['THROWS_WEB_SMOKE_GATE'] ?? '0';
        $serveAotDefault = $defaults['THROWSWEB_SERVE_AOT_SMOKE_GATE'] ?? '0';
        $serveJitDefault = $defaults['THROWSWEB_SERVE_JIT_SMOKE_GATE'] ?? '0';
        $uncaughtDefault = $defaults['THROWSWEB_UNCAUGHT_500_GATE'] ?? '0';
        $linkDefault = $defaults['THROWSWEB_AOT_LINK_GATE'] ?? '1';
        $aotDefault = $defaults['THROWSWEB_AOT_SMOKE_GATE'] ?? '1';
        $deployDefault = $defaults['THROWSWEB_DEPLOY_SMOKE_GATE'] ?? '0';

        $smokeOn = self::gateEnabled('THROWS_WEB_SMOKE_GATE', $smokeDefault);
        $serveAotOn = self::gateEnabled('THROWSWEB_SERVE_AOT_SMOKE_GATE', $serveAotDefault);
        $serveJitOn = self::gateEnabled('THROWSWEB_SERVE_JIT_SMOKE_GATE', $serveJitDefault);
        $uncaughtOn = self::gateEnabled('THROWSWEB_UNCAUGHT_500_GATE', $uncaughtDefault);
        $linkOn = self::gateEnabled('THROWSWEB_AOT_LINK_GATE', $linkDefault);
        $aotOn = self::gateEnabled('THROWSWEB_AOT_SMOKE_GATE', $aotDefault);
        $deployOn = self::gateEnabled('THROWSWEB_DEPLOY_SMOKE_GATE', $deployDefault);

        $llvmInfo = self::resolveLlvmInfo($repoRoot);
        $llvmReady = null !== $llvmInfo['dir'];
        $llvmDetail = $llvmReady
            ? 'LLVM ready at '.$llvmInfo['dir']
            : 'LLVM missing — AOT rows need libLLVM-9.so.1';

        $hasExample = is_file($exampleDir.'/example.php');
        $hasManifest = is_file($exampleDir.'/phpc.json');

        fwrite(STDOUT, "\n007-ThrowsWeb CI gates (#2102, ladder #2076–#2101):\n");
        fwrite(STDOUT, "  Tree: examples/007-ThrowsWeb\n");
        fwrite(STDOUT, "  {$llvmDetail}\n");
        fwrite(STDOUT, "  Defaults: script/ci-defaults.env\n\n");

        if ($hasExample && $hasManifest) {
            fwrite(STDOUT, "  [✅] example.php + phpc.json present\n");
        } else {
            fwrite(STDOUT, "  [⬜] example tree incomplete (expected example.php + phpc.json)\n");
        }
        fwrite(STDOUT, "  Lint: ./phpc lint examples/007-ThrowsWeb/example.php\n\n");

        self::printSessionsWebGateRow(
            1,
            'VM throw/catch',
            'THROWS_WEB_SMOKE_GATE',
            $smokeDefault,
            $smokeOn,
            false,
            'make examples-throws-smoke · examples-web-smoke.sh --throws-only · ci-fast (#2125)',
            '#2093'
        );
        self::printSessionsWebGateRow(
            2,
            'VM uncaught 500',
            'THROWSWEB_UNCAUGHT_500_GATE',
            $uncaughtDefault,
            $uncaughtOn,
            false,
            'THROWSWEB_UNCAUGHT_500_GATE=1 ./script/examples-web-smoke.sh --throws-only · ci-fast (#2200)',
            '#2200'
        );
        $serveAotStatus = $serveAotOn && $llvmReady ? '✅' : '📋';
        $serveAotNote = $llvmReady
            ? ($serveAotOn ? '#2390 ✅' : 'opt-out — phpc serve --aot caught invalid POST (#2390, #2387)')
            : 'LLVM required; #2390 when gate=1';
        fwrite(STDOUT, "  [{$serveAotStatus}] Stage 2b AOT serve — THROWSWEB_SERVE_AOT_SMOKE_GATE default {$serveAotDefault} ({$serveAotNote})\n");
        fwrite(STDOUT, "      Run:     THROWSWEB_SERVE_AOT_SMOKE_GATE=1 ./script/examples-web-smoke.sh --throws-only --aot\n");
        $serveJitStatus = $serveJitOn && $llvmReady ? '✅' : '📋';
        $serveJitNote = $llvmReady
            ? ($serveJitOn ? '#2435 ✅' : 'opt-out — phpc serve --jit caught invalid POST (#2435, #2408)')
            : 'LLVM required; #2435 when gate=1';
        fwrite(STDOUT, "  [{$serveJitStatus}] Stage 2c JIT serve — THROWSWEB_SERVE_JIT_SMOKE_GATE default {$serveJitDefault} ({$serveJitNote})\n");
        fwrite(STDOUT, "      Run:     THROWSWEB_SERVE_JIT_SMOKE_GATE=1 ./script/examples-web-smoke.sh --throws-only --jit\n");
        self::printSessionsWebGateRow(
            3,
            'AOT link',
            'THROWSWEB_AOT_LINK_GATE',
            $linkDefault,
            $linkOn,
            true,
            './script/ci-local.sh --filter test007ThrowsWebAotLink (#2101, #2143)',
            '#2101'
        );
        $aotStatus = $aotOn && $llvmReady ? '✅' : '📋';
        $aotExecuteNote = $llvmReady
            ? ($aotOn ? '#2101 · #2157 · default-on #2135' : '#2101 · set THROWSWEB_AOT_SMOKE_GATE=1')
            : 'LLVM required; #2101 when gate=1';
        fwrite(STDOUT, "  [{$aotStatus}] Stage 4 AOT execute — THROWSWEB_AOT_SMOKE_GATE default {$aotDefault} ({$aotExecuteNote})\n");
        fwrite(STDOUT, "      PHPUnit: ./script/ci-local.sh --filter ThrowsWebAotExecuteTest\n");
        fwrite(STDOUT, "      Shell:   THROWSWEB_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=007 ./script/examples-aot-smoke.sh (#2104)\n");
        $deployStatus = $deployOn && $llvmReady ? '✅' : '📋';
        $deployNote = $deployOn
            ? '#2124 ✅ · ci-local when THROWSWEB_DEPLOY_SMOKE_GATE=1 (#2264)'
            : 'opt-in default 0 — #2124 · #2264';
        fwrite(STDOUT, "  [{$deployStatus}] Stage 5 Deploy CGI — THROWSWEB_DEPLOY_SMOKE_GATE default {$deployDefault} ({$deployNote})\n");
        fwrite(STDOUT, "      Run:     THROWSWEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all\n");
        fwrite(STDOUT, "      Or:      make examples-throwsweb-deploy-smoke\n");
        fwrite(STDOUT, "      Ladder:  make deploy-smoke-all (skips 007 with hint when gate=0; #2077)\n");

        $initProfileLive = \PHPCompiler\Cli\PhpcInit::isKnownProfile('throwsweb');
        $initTemplate = is_file($repoRoot.'/templates/init-throwsweb/example.php');
        fwrite(STDOUT, "\n  Related:\n");
        if ($initProfileLive) {
            fwrite(STDOUT, "  [✅] phpc init --profile throwsweb (#2092)\n");
        } elseif ($initTemplate) {
            fwrite(STDOUT, "  [📋] phpc init --profile throwsweb — template ready; CLI profile pending #2092\n");
        } else {
            fwrite(STDOUT, "  [📋] phpc init --profile throwsweb — #2092\n");
        }
        fwrite(STDOUT, "  Docs: examples/007-ThrowsWeb/README.md · docs/local-ci-matrix.md (#2102)\n");
    }

    /**
     * 009-FastCGIWeb gate ladder (issues #2331, #2351, #2352).
     */
    private static function printFastCGIWebSection(string $repoRoot): void
    {
        $exampleDir = $repoRoot.'/examples/009-FastCGIWeb';
        if (!is_dir($exampleDir)) {
            return;
        }

        $defaults = self::readCiDefaultsEnv($repoRoot);
        $smokeDefault = $defaults['FASTCGI_WEB_SMOKE_GATE'] ?? '0';
        $aotDefault = $defaults['FASTCGI_WEB_AOT_SMOKE_GATE'] ?? '0';
        $deployDefault = $defaults['FASTCGI_WEB_DEPLOY_SMOKE_GATE'] ?? '0';

        $smokeOn = self::gateEnabled('FASTCGI_WEB_SMOKE_GATE', $smokeDefault);
        $aotOn = self::gateEnabled('FASTCGI_WEB_AOT_SMOKE_GATE', $aotDefault);
        $deployOn = self::gateEnabled('FASTCGI_WEB_DEPLOY_SMOKE_GATE', $deployDefault);

        $llvmInfo = self::resolveLlvmInfo($repoRoot);
        $llvmReady = null !== $llvmInfo['dir'];
        $llvmDetail = $llvmReady
            ? 'LLVM ready at '.$llvmInfo['dir']
            : 'LLVM missing — AOT execute row needs libLLVM-9.so.1';

        $hasExample = is_file($exampleDir.'/example.php');
        $hasManifest = is_file($exampleDir.'/phpc.json');

        fwrite(STDOUT, "\n009-FastCGIWeb CI gates (#2331, #2351, #2352):\n");
        fwrite(STDOUT, "  Tree: examples/009-FastCGIWeb\n");
        fwrite(STDOUT, "  {$llvmDetail}\n");
        fwrite(STDOUT, "  Defaults: script/ci-defaults.env\n\n");

        if ($hasExample && $hasManifest) {
            fwrite(STDOUT, "  [✅] example.php + phpc.json present\n");
        } else {
            fwrite(STDOUT, "  [⬜] example tree incomplete (expected example.php + phpc.json)\n");
        }
        fwrite(STDOUT, "  Lint: ./phpc lint examples/009-FastCGIWeb/example.php\n\n");

        self::printSessionsWebGateRow(
            1,
            'VM serve health + PATH_INFO',
            'FASTCGI_WEB_SMOKE_GATE',
            $smokeDefault,
            $smokeOn,
            false,
            'make examples-fastcgiweb-smoke · examples-web-smoke.sh --fastcgi-only · ci-fast when gate=1 (#2351)',
            '#2351'
        );
        $aotStatus = $aotOn && $llvmReady ? '✅' : '📋';
        $aotExecuteNote = $llvmReady
            ? ($aotOn ? '#2352 · FASTCGI_WEB_AOT_SMOKE_GATE=1' : '#2352 · opt-in default 0')
            : 'LLVM required; #2352 when gate=1';
        fwrite(STDOUT, "  [{$aotStatus}] Stage 2 AOT execute — FASTCGI_WEB_AOT_SMOKE_GATE default {$aotDefault} ({$aotExecuteNote})\n");
        fwrite(STDOUT, "      Shell:   FASTCGI_WEB_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=009 ./script/examples-aot-smoke.sh\n");
        fwrite(STDOUT, "      PHPUnit: ./script/ci-local.sh --filter FastCGIWebAotExecuteTest (when present)\n");
        $deployStatus = $deployOn && $llvmReady ? '✅' : '📋';
        $deployNote = $deployOn
            ? '#2359 ✅ · ci-local when FASTCGI_WEB_DEPLOY_SMOKE_GATE=1'
            : 'opt-in default 0 — #2359';
        fwrite(STDOUT, "  [{$deployStatus}] Stage 3 Deploy CGI — FASTCGI_WEB_DEPLOY_SMOKE_GATE default {$deployDefault} ({$deployNote})\n");
        fwrite(STDOUT, "      Run:     FASTCGI_WEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke-all\n");
        fwrite(STDOUT, "      Or:      make examples-fastcgiweb-deploy-smoke\n");
        fwrite(STDOUT, "      Ladder:  make deploy-smoke-all (skips 009 with hint when gate=0; #2077)\n");

        $initProfileLive = \PHPCompiler\Cli\PhpcInit::isKnownProfile('fastcgiweb');
        $initTemplate = is_file($exampleDir.'/example.php')
            && is_file($repoRoot.'/templates/init-fastcgiweb/example.php');
        if ($initProfileLive && $initTemplate) {
            fwrite(STDOUT, "  [✅] phpc init --profile fastcgiweb (#2342)\n");
        } elseif ($initTemplate) {
            fwrite(STDOUT, "  [📋] phpc init --profile fastcgiweb — template ready; CLI profile pending #2342\n");
        } else {
            fwrite(STDOUT, "  [📋] phpc init --profile fastcgiweb — #2342\n");
        }
        fwrite(STDOUT, "      Parity:  ./script/check-init-fastcgiweb-parity.sh (INIT_FASTCGIWEB_PARITY_GATE=1 in ci-fast)\n");

        fwrite(STDOUT, "\n  Related:\n");
        fwrite(STDOUT, "  [📋] FastCGI adapter loop — #173\n");
        fwrite(STDOUT, "  Docs: examples/009-FastCGIWeb/README.md · docs/local-ci-matrix.md\n");
    }

    /**
     * 008-SelfHostProbe VM smoke gate (issues #2207, #2240, #2302).
     */
    private static function printSelfHostProbeSection(string $repoRoot): void
    {
        $example = $repoRoot.'/examples/008-SelfHostProbe/example.php';
        if (!is_file($example)) {
            return;
        }

        $defaults = self::readCiDefaultsEnv($repoRoot);
        $smokeDefault = $defaults['EXAMPLES_SELFHOSTPROBE_SMOKE_GATE'] ?? '1';
        $smokeOn = self::gateEnabled('EXAMPLES_SELFHOSTPROBE_SMOKE_GATE', $smokeDefault);
        $aotDefault = $defaults['SELFHOSTPROBE_AOT_SMOKE_GATE'] ?? '1';
        $aotOn = self::gateEnabled('SELFHOSTPROBE_AOT_SMOKE_GATE', $aotDefault);

        fwrite(STDOUT, "\n008-SelfHostProbe CI gates (#2343, #2240, #2407):\n");
        fwrite(STDOUT, "  Tree: examples/008-SelfHostProbe\n");
        fwrite(STDOUT, "  Defaults: script/ci-defaults.env\n\n");
        self::printSessionsWebGateRow(
            1,
            'VM lint + run',
            'EXAMPLES_SELFHOSTPROBE_SMOKE_GATE',
            $smokeDefault,
            $smokeOn,
            false,
            'make examples-selfhostprobe-smoke · ci-fast default-on (#2343); opt-out EXAMPLES_SELFHOSTPROBE_SMOKE_GATE=0',
            '#2343'
        );
        self::printSessionsWebGateRow(
            2,
            'AOT build + execute',
            'SELFHOSTPROBE_AOT_SMOKE_GATE',
            $aotDefault,
            $aotOn,
            false,
            'SELFHOSTPROBE_AOT_SMOKE_GATE=1 EXAMPLES_AOT_SMOKE_ONLY=008 ./script/examples-aot-smoke.sh · north-star3-verify (#2407)',
            '#2407'
        );
        $rootReadme008Default = $defaults['ROOT_README_008_SYNC_GATE'] ?? '0';
        $rootReadme008On = self::gateEnabled('ROOT_README_008_SYNC_GATE', $rootReadme008Default);
        $rootReadme008Icon = $rootReadme008On ? '✅' : '⬜';
        fwrite(STDOUT, "\n  Doc sync (ci-fast inventory):\n");
        fwrite(STDOUT, "  [{$rootReadme008Icon}] README 008 stale phrases — ROOT_README_008_SYNC_GATE default {$rootReadme008Default} (#2229)\n");
        fwrite(STDOUT, "      Run: ROOT_README_008_SYNC_GATE=1 php script/check-root-readme-sync.php · ci-fast when gate=1\n");
        $initProfileLive = \PHPCompiler\Cli\PhpcInit::isKnownProfile('selfhostprobe');
        $initTemplate = is_file($repoRoot.'/templates/init-selfhostprobe/example.php');
        fwrite(STDOUT, "\n  Related:\n");
        if ($initProfileLive) {
            fwrite(STDOUT, "  [✅] phpc init --profile selfhostprobe (#2220)\n");
        } elseif ($initTemplate) {
            fwrite(STDOUT, "  [📋] phpc init --profile selfhostprobe — template ready; CLI profile pending #2220\n");
        } else {
            fwrite(STDOUT, "  [📋] phpc init --profile selfhostprobe — #2220\n");
        }
        fwrite(STDOUT, "  Docs: examples/008-SelfHostProbe/README.md\n");
    }

    /**
     * @param array<string, string> $defaults
     */
    private static function printSessionsWebGateRow(
        int $stage,
        string $label,
        string $envVar,
        string $default,
        bool $enabled,
        bool $needsLlvm,
        string $runHint,
        string $issueRef
    ): void {
        $icon = $enabled ? '✅' : '⬜';
        $llvmTag = $needsLlvm ? ' · LLVM' : '';
        fwrite(STDOUT, "  [{$icon}] Stage {$stage} {$label} — {$envVar} default {$default}{$llvmTag} ({$issueRef})\n");
        fwrite(STDOUT, "      Run: {$runHint}\n");
    }

    /**
     * @return array<string, string>
     */
    private static function readCiDefaultsEnv(string $repoRoot): array
    {
        $path = $repoRoot.'/script/ci-defaults.env';
        if (!is_readable($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if (false === $content) {
            return [];
        }
        $defaults = [];
        if (preg_match_all(
            '/export\s+([A-Z][A-Z0-9_]+)="\$\{\1:-([^}]+)\}"/',
            $content,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $defaults[$match[1]] = $match[2];
            }
        }

        return $defaults;
    }

    private static function gateEnabled(string $name, string $default): bool
    {
        $value = getenv($name);
        if (false === $value || '' === $value) {
            return '1' === $default;
        }

        return '1' === $value;
    }

    /**
     * @return list<array{name: string, ok: bool, required: bool, detail: string, hint: string}>
     */
    private static function collectChecks(string $repoRoot): array
    {
        $checks = [];
        $checks[] = self::checkPhpVersion();
        $checks = array_merge($checks, self::checkExtensions());
        $checks[] = self::checkVendor($repoRoot);
        $checks[] = self::checkLlvm($repoRoot);
        $checks[] = self::checkJitCompliance($repoRoot);
        $checks[] = self::checkLoopback($repoRoot);
        $checks[] = self::checkDockerImage();
        $checks[] = self::checkPhpcJsonManifest($repoRoot);

        return $checks;
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string, hint: string}
     */
    private static function checkPhpVersion(): array
    {
        $version = PHP_VERSION;
        $ok = version_compare($version, '8.1.0', '>=');

        return [
            'name' => 'PHP',
            'ok' => $ok,
            'required' => true,
            'detail' => $ok ? $version : $version.' (need >= 8.1)',
            'hint' => $ok ? '' : 'Use php-compiler:22.04-dev or install PHP 8.1+',
        ];
    }

    /**
     * @return list<array{name: string, ok: bool, required: bool, detail: string, hint: string}>
     */
    private static function checkExtensions(): array
    {
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        $checks = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $onDisk = is_dir($extDir) && is_file($extDir.'/'.$ext.'.so');
            $checks[] = [
                'name' => 'ext-'.$ext,
                'ok' => $loaded,
                'required' => true,
                'detail' => $loaded ? 'loaded' : ($onDisk ? 'not loaded (.so present)' : 'missing'),
                'hint' => $loaded ? '' : 'apt install php-'.$ext.' or run via ./phpc (loads PHP_COMPILER_EXT_DIR)',
            ];
        }

        return $checks;
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string, hint: string}
     */
    private static function checkVendor(string $repoRoot): array
    {
        $autoload = $repoRoot.'/vendor/autoload.php';
        $phpunit = $repoRoot.'/vendor/bin/phpunit';
        $prePlugin = $repoRoot.'/vendor/pre/plugin';
        $ok = is_file($autoload) && is_executable($phpunit) && is_dir($prePlugin);

        return [
            'name' => 'Composer deps',
            'ok' => $ok,
            'required' => true,
            'detail' => $ok ? 'vendor/ installed (pre/plugin present)' : 'vendor/ incomplete',
            'hint' => 'composer install --no-interaction && script/apply-patches.sh',
        ];
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string, hint: string}
     */
    private static function checkLlvm(string $repoRoot): array
    {
        $info = self::resolveLlvmInfo($repoRoot);
        if (null === $info['dir']) {
            $envHint = getenv('PHP_COMPILER_LLVM_PATH');
            $envSet = false !== $envHint && '' !== $envHint ? $envHint : '(unset)';

            return [
                'name' => 'LLVM 9',
                'ok' => false,
                'required' => true,
                'detail' => 'libLLVM-9.so.1: no (PHP_COMPILER_LLVM_PATH='.$envSet.')',
                'hint' => 'script/install-llvm9.sh  or  docker run php-compiler:22.04-dev (has /opt/llvm9)',
            ];
        }

        return [
            'name' => 'LLVM 9',
            'ok' => true,
            'required' => true,
            'detail' => 'libLLVM-9.so.1: yes at '.$info['dir'].' (from '.$info['source'].')',
            'hint' => '',
        ];
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string, hint: string}
     */
    private static function checkJitCompliance(string $repoRoot): array
    {
        $info = self::resolveLlvmInfo($repoRoot);
        if (null === $info['dir']) {
            return [
                'name' => 'JIT compliance',
                'ok' => false,
                'required' => false,
                'detail' => 'LLVM missing — JITTest @group llvm skipped in ci-local',
                'hint' => 'Use ./script/ci-fast.sh or phpc test --fast for VM-only iteration',
            ];
        }

        self::applyLlvmProcessEnv($info['dir']);
        if (!self::probePhpllvmChooser()) {
            return [
                'name' => 'JIT compliance',
                'ok' => false,
                'required' => false,
                'detail' => 'LLVM present but PHPLLVM bootstrap failed',
                'hint' => 'issue #98 — export PHP_COMPILER_LLVM_PATH; prepend LLVM dir to LD_LIBRARY_PATH and PATH; avoid broken host .llvm bind-mount',
            ];
        }

        $probeExit = self::jitRuntimeProbeExit($repoRoot);
        if (0 !== $probeExit) {
            return [
                'name' => 'JIT compliance',
                'ok' => false,
                'required' => false,
                'detail' => 'MCJIT probe failed (bin/jit.php trivial compile)',
                'hint' => 'issue #98 — same LLVM PATH/LD_LIBRARY_PATH fix; phpc doctor --jit-probe for details',
            ];
        }

        return [
            'name' => 'JIT compliance',
            'ok' => true,
            'required' => false,
            'detail' => 'ready — JITTest should execute in ci-local.sh (not 100% skipped)',
            'hint' => '',
        ];
    }

    private static function jitRuntimeProbeExit(string $repoRoot): int
    {
        if (null !== self::$jitRuntimeProbeExit) {
            return self::$jitRuntimeProbeExit;
        }
        self::$jitRuntimeProbeExit = self::runJitRuntimeProbe($repoRoot, false);

        return self::$jitRuntimeProbeExit;
    }

    /**
     * Run script/jit-runtime-probe.php (MCJIT smoke). Returns probe exit code.
     */
    public static function runJitRuntimeProbe(string $repoRoot, bool $echoOutput = true): int
    {
        if (!$echoOutput && null !== self::$jitRuntimeProbeExit) {
            return self::$jitRuntimeProbeExit;
        }
        $script = $repoRoot.'/script/jit-runtime-probe.php';
        if (!is_file($script)) {
            if ($echoOutput) {
                fwrite(STDERR, "JIT probe: {$script} missing\n");
            }

            return 2;
        }

        $info = self::resolveLlvmInfo($repoRoot);
        if (null === $info['dir']) {
            if ($echoOutput) {
                fwrite(STDOUT, "JIT probe skipped: LLVM 9 not found\n");
            }

            return 0;
        }

        $cmd = array_merge(self::phpBinary(), [$script]);
        $env = $_ENV;
        self::applyLlvmProcessEnvToArray($info['dir'], $env);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $env);
        if (!is_resource($proc)) {
            if ($echoOutput) {
                fwrite(STDERR, "JIT probe: failed to start jit-runtime-probe.php\n");
            }

            return 2;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($echoOutput) {
            if (false !== $stdout && '' !== $stdout) {
                fwrite(STDOUT, $stdout);
            }
            if (false !== $stderr && '' !== $stderr) {
                fwrite(STDERR, $stderr);
            }
        }

        $code = is_int($exit) ? $exit : 1;
        if (!$echoOutput) {
            self::$jitRuntimeProbeExit = $code;
        }

        return $code;
    }

    /**
     * Run script/aot-project-probe.php (MiniWebApp AOT build + execute — issue #746).
     *
     * @param string|null $projectDir Absolute project path, or null for examples/003-MiniWebApp
     */
    public static function runAotProjectProbe(string $repoRoot, ?string $projectDir = null, bool $echoOutput = true): int
    {
        $script = $repoRoot.'/script/aot-project-probe.php';
        if (!is_file($script)) {
            if ($echoOutput) {
                fwrite(STDERR, "AOT project probe: {$script} missing\n");
            }

            return 2;
        }

        $cmd = self::phpBinary();
        $cmd[] = $script;
        if (null !== $projectDir && '' !== $projectDir) {
            $resolved = realpath($projectDir);
            if (false === $resolved) {
                if ($echoOutput) {
                    fwrite(STDERR, "AOT project probe: project directory not found: {$projectDir}\n");
                }

                return 2;
            }
            $default = realpath($repoRoot.'/examples/003-MiniWebApp');
            if (false === $default || $resolved !== $default) {
                $cmd[] = self::relativeProjectArg($repoRoot, $resolved);
            }
        }

        $info = self::resolveLlvmInfo($repoRoot);
        $env = $_ENV;
        if (null !== $info['dir']) {
            self::applyLlvmProcessEnvToArray($info['dir'], $env);
        }
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $env);
        if (!is_resource($proc)) {
            if ($echoOutput) {
                fwrite(STDERR, "AOT project probe: failed to start aot-project-probe.php\n");
            }

            return 2;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($echoOutput) {
            if (false !== $stdout && '' !== $stdout) {
                fwrite(STDOUT, $stdout);
            }
            if (false !== $stderr && '' !== $stderr) {
                fwrite(STDERR, $stderr);
            }
        }

        return is_int($exit) ? $exit : 1;
    }

    private static function relativeProjectArg(string $repoRoot, string $projectDir): string
    {
        $root = realpath($repoRoot);
        if (false === $root) {
            return $projectDir;
        }
        $prefix = $root.'/';
        if (str_starts_with($projectDir, $prefix)) {
            return substr($projectDir, strlen($prefix));
        }

        return $projectDir;
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string, hint: string}
     */
    private static function checkLoopback(string $repoRoot): array
    {
        $skipServe = getenv('PHP_COMPILER_SKIP_SERVE_TESTS');
        if (false !== $skipServe && '' !== $skipServe) {
            return [
                'name' => 'Loopback TCP',
                'ok' => false,
                'required' => false,
                'detail' => 'skipped (PHP_COMPILER_SKIP_SERVE_TESTS is set)',
                'hint' => 'Unset PHP_COMPILER_SKIP_SERVE_TESTS in Docker/harness for @group serve tests',
            ];
        }

        $probe = $repoRoot.'/script/can-bind-loopback.php';
        $php = self::phpBinary();
        $cmd = array_merge($php, [$probe]);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        if (!is_resource($proc)) {
            return [
                'name' => 'Loopback TCP',
                'ok' => false,
                'required' => false,
                'detail' => 'probe failed to start',
                'hint' => 'Run script/can-bind-loopback.php manually',
            ];
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $ok = 0 === $exit;

        return [
            'name' => 'Loopback TCP',
            'ok' => $ok,
            'required' => false,
            'detail' => $ok ? '127.0.0.1 bind OK (@group serve)' : trim($stderr !== false ? $stderr : 'bind failed'),
            'hint' => $ok ? '' : 'ServeTest skipped when bind fails; use Docker dev image or fix network policy',
        ];
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string, hint: string}
     */
    private static function checkPhpcJsonManifest(string $repoRoot): array
    {
        $manifest = $repoRoot.'/phpc.json';
        if (!is_file($manifest)) {
            return [
                'name' => 'phpc.json',
                'ok' => true,
                'required' => false,
                'detail' => 'not present (optional)',
                'hint' => '',
            ];
        }

        $errors = \PHPCompiler\Web\ManifestValidator::validate($repoRoot);
        $ok = [] === $errors;

        return [
            'name' => 'phpc.json',
            'ok' => $ok,
            'required' => false,
            'detail' => $ok ? 'valid manifest' : implode('; ', $errors),
            'hint' => $ok ? '' : 'phpc validate-manifest '.$repoRoot,
        ];
    }

    /**
     * @return array{name: string, ok: bool, required: bool, detail: string, hint: string}
     */
    private static function checkDockerImage(): array
    {
        $image = getenv('PHP_COMPILER_DEV_IMAGE') ?: 'php-compiler:22.04-dev';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            ['docker', 'image', 'inspect', $image],
            $descriptorSpec,
            $pipes,
            null
        );
        if (!is_resource($proc)) {
            return [
                'name' => 'Docker image',
                'ok' => false,
                'required' => false,
                'detail' => 'docker CLI unavailable (optional)',
                'hint' => '',
            ];
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $ok = 0 === $exit;

        return [
            'name' => 'Docker image',
            'ok' => $ok,
            'required' => false,
            'detail' => $ok ? $image.' present' : $image.' not found (optional)',
            'hint' => $ok ? '' : 'make docker-build-22  then  ./script/docker-ci-local.sh',
        ];
    }

    /**
     * @return array{dir: non-empty-string|null, source: string}
     */
    private static function resolveLlvmInfo(string $repoRoot): array
    {
        $candidates = [];
        $fromEnv = getenv('PHP_COMPILER_LLVM_PATH');
        if (false !== $fromEnv && '' !== $fromEnv) {
            $candidates[] = ['dir' => $fromEnv, 'source' => 'PHP_COMPILER_LLVM_PATH'];
        }
        $candidates[] = ['dir' => $repoRoot.'/.llvm', 'source' => '.llvm/'];
        $candidates[] = ['dir' => '/opt/llvm9', 'source' => '/opt/llvm9'];

        foreach ($candidates as $candidate) {
            $dir = $candidate['dir'];
            if (is_file($dir.'/libLLVM-9.so.1')) {
                $resolved = realpath($dir);
                $resolvedDir = false !== $resolved ? $resolved : $dir;

                return ['dir' => $resolvedDir, 'source' => $candidate['source']];
            }
        }

        return ['dir' => null, 'source' => ''];
    }

    private static function applyLlvmProcessEnv(string $llvmDir): void
    {
        if ('' === getenv('PHP_COMPILER_LLVM_PATH')) {
            putenv('PHP_COMPILER_LLVM_PATH='.$llvmDir);
            $_ENV['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
            $_SERVER['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
        }
        $ld = getenv('LD_LIBRARY_PATH');
        $ldVal = false === $ld || '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
        putenv('LD_LIBRARY_PATH='.$ldVal);
        $path = getenv('PATH');
        $pathVal = false === $path || '' === $path ? $llvmDir : $llvmDir.':'.$path;
        putenv('PATH='.$pathVal);
    }

    /**
     * @param array<string, string> $env
     */
    private static function applyLlvmProcessEnvToArray(string $llvmDir, array &$env): void
    {
        $env['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
        $ld = $env['LD_LIBRARY_PATH'] ?? getenv('LD_LIBRARY_PATH') ?: '';
        $env['LD_LIBRARY_PATH'] = '' === $ld ? $llvmDir : $llvmDir.':'.$ld;
        $path = $env['PATH'] ?? getenv('PATH') ?: '';
        $env['PATH'] = '' === $path ? $llvmDir : $llvmDir.':'.$path;
    }

    private static function probePhpllvmChooser(): bool
    {
        if (!class_exists(\PHPLLVM\Chooser::class)) {
            return false;
        }
        try {
            \PHPLLVM\Chooser::choose();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private static function phpBinary(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (self::REQUIRED_EXTENSIONS as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }

        return $cmd;
    }
}
