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

        self::printNorthStar1PresenterSection($repoRoot);
        self::printNorthStar2PresenterSection($repoRoot);
        self::printSessionsWebSection($repoRoot);

        return is_int($exit) ? $exit : 1;
    }

    /**
     * North Star 1 presenter commands after the gate ladder (issues #1845, #1857).
     */
    private static function printNorthStar1PresenterSection(string $repoRoot): void
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

        fwrite(STDOUT, "\nNorth Star 1 presenter (#1044, #1845):\n");
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
        $stringKeyJitGate = getenv('STRING_KEY_JIT_COMPLIANCE_GATE');
        $stringKeyJitOn = false === $stringKeyJitGate || '' === $stringKeyJitGate || '1' === $stringKeyJitGate;
        $stringKeyJitDetail = $stringKeyJitOn
            ? 'default on — ci-fast array_rehash_string_keys_jit (#1959, LLVM)'
            : 'skipped (STRING_KEY_JIT_COMPLIANCE_GATE=0)';

        fwrite(STDOUT, "  Gates ladder     make miniwebapp-gates              stages 0–4d (#472)\n");
        fwrite(STDOUT, "  Fast CI          ./script/ci-fast.sh               VM/compliance\n");
        fwrite(STDOUT, "  Nested return    {$nestedReturnDetail}\n");
        fwrite(STDOUT, "  Attributes       {$attributesDetail}\n");
        fwrite(STDOUT, "  HashTable rehash {$rehashDetail}\n");
        fwrite(STDOUT, "  String-key JIT   {$stringKeyJitDetail}\n");
        fwrite(STDOUT, "  Full AOT tail    ./script/ci-local.sh --filter MiniWebAppAotExecuteTest   LLVM required\n");
        fwrite(STDOUT, "  Presenter bundle make north-star1-verify            --require-llvm / --skip-llvm-tail\n");
        fwrite(STDOUT, "  Script           ./script/north-star1-verify.sh    same as make target\n");
        fwrite(STDOUT, "  Docs             docs/miniwebapp-gates.md\n");
    }

    /**
     * North Star 2 self-host presenter commands (issues #1492, #1871; bundle #1865).
     */
    private static function printNorthStar2PresenterSection(string $repoRoot): void
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

        fwrite(STDOUT, "\nNorth Star 2 presenter (#1492, #1871):\n");
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
        $loopProbeGate = getenv('BOOTSTRAP_LOOP_PROBE_GATE');
        $loopProbeOn = false !== $loopProbeGate && '1' === $loopProbeGate;
        $loopProbeDetail = $loopProbeOn
            ? 'BOOTSTRAP_LOOP_PROBE_GATE=1 — ci-fast / ci-local dry-run (#1929)'
            : 'opt-in BOOTSTRAP_LOOP_PROBE_GATE=1 for M4 dry-run in ci-fast (#1929)';
        fwrite(STDOUT, "  M4 loop dry-run  {$loopProbeDetail}\n");
        $ns2CiGate = getenv('NORTH_STAR2_VERIFY_GATE');
        $ns2CiOn = false !== $ns2CiGate && '1' === $ns2CiGate;
        $ns2CiDetail = $ns2CiOn
            ? 'NORTH_STAR2_VERIFY_GATE=1 — ci-fast runs presenter (#1928)'
            : 'opt-in NORTH_STAR2_VERIFY_GATE=1 for ci-fast hook (#1928)';
        if ($ns2Make) {
            fwrite(STDOUT, "  Presenter bundle make north-star2-verify            --require-llvm / --skip-llvm-tail\n");
            fwrite(STDOUT, "  Script           ./script/north-star2-verify.sh    same as make target\n");
        } else {
            fwrite(STDOUT, "  Presenter bundle make north-star2-verify            pending — see #1865\n");
        }
        fwrite(STDOUT, "  Fast CI hook     {$ns2CiDetail}\n");
        fwrite(STDOUT, "  Docs             docs/bootstrap-selfhost.md · docs/self-host-target.md (#1492)\n");
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
        $linkDefault = $defaults['SESSIONS_WEB_AOT_LINK_GATE'] ?? '1';
        $aotDefault = $defaults['SESSIONS_WEB_AOT_SMOKE_GATE'] ?? '0';
        $deployDefault = $defaults['SESSIONS_WEB_DEPLOY_SMOKE_GATE'] ?? '0';

        $smokeOn = self::gateEnabled('SESSIONS_WEB_SMOKE_GATE', $smokeDefault);
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
        fwrite(STDOUT, "      Run:     SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 make deploy-smoke\n");
        fwrite(STDOUT, "      Or:      SESSIONS_WEB_DEPLOY_SMOKE_GATE=1 ./script/deploy-smoke.sh --example 005\n");

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
            '/export\s+(SESSIONS_WEB_[A-Z_]+)="\$\{\1:-([^}]+)\}"/',
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
