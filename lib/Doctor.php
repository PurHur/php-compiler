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

        return is_int($exit) ? $exit : 1;
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
