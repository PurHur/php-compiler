<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group bootstrap */
final class BootstrapHonestCompileLibTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }
    public function testHonestCompileLibDetectsSidecarRecovery(): void
    {
        $lib = self::$root.'/script/bootstrap-honest-compile-lib.sh';
        $this->assertFileExists($lib);
        $log = <<<'LOG'
helloworld_compile_smoke: parseAndCompile returned null (parser/CFG spine)
bootstrap-compile-invoke: gen-0 sidecar emit fallback /build/.m3_compiler_lib_aot_blob -> /build/out (#3046)
bootstrap-compile-invoke: native parse spine null — recovered via gen-0 sidecar (#1492)
LOG;
        $code = $this->runBashCheck($log);
        $this->assertSame(0, $code, 'expected sidecar recovery detection');
    }

    public function testHonestCompileLibPassesCleanNativeLog(): void
    {
        $log = <<<'LOG'
bootstrap-compile-invoke: /build/bin-compile-aot-inventory -o /build/out /compiler/test/foo.php (gen-0 compiled)
helloworld_compile_smoke: compile OK -> /build/out
LOG;
        $code = $this->runBashCheck($log);
        $this->assertSame(1, $code, 'expected no sidecar recovery');
    }

    public function testHonestCompileGateFailsWhenEnabled(): void
    {
        $log = 'recovered via gen-0 sidecar';
        $lib = self::$root.'/script/bootstrap-honest-compile-lib.sh';
        $cmd = 'source '.escapeshellarg($lib)
            .'; BOOTSTRAP_HONEST_COMPILE_GATE=1 bootstrap_honest_compile_gate_check '
            .escapeshellarg($log).' test-label';
        $proc = proc_open(['bash', '-lc', $cmd], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('BOOTSTRAP_HONEST_COMPILE_GATE=1', (string) $stderr);
    }

    public function testBootstrapLoopProbeDocumentsHonestCompileFlag(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-probe.sh');
        $this->assertStringContainsString('--honest-compile', $script);
        $this->assertStringContainsString('BOOTSTRAP_HONEST_COMPILE_GATE=1', $script);
    }

    public function testBootstrapHonestCompileMetricScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-honest-compile-metric.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap-inventory-argv-probe.sh', $body);
        $this->assertStringContainsString('#15603', $body);
    }

    public function testBootstrapHonestCompileMetricCheckJson(): void
    {
        $script = self::$root.'/script/bootstrap-honest-compile-metric.sh';
        $cmd = 'bash '.escapeshellarg($script).' --check --json 2>/dev/null';
        $raw = shell_exec($cmd);
        $this->assertIsString($raw);
        $payload = json_decode((string) $raw, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertSame('unknown', $payload['status']);
        $this->assertTrue($payload['gate_available']);
    }

    public function testBootstrapInitScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-init.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M5_NO_ZEND=1', $body);
        $this->assertStringContainsString('--with-composer', $body);
        $this->assertStringContainsString('--sdk-url', $body);
        $this->assertStringContainsString('PHP_COMPILER_BOOTSTRAP_SDK', $body);
        $this->assertStringContainsString('bootstrap-sdk-fetch.sh', $body);
    }

    public function testBootstrapSdkFetchScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-sdk-fetch.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('PHP_COMPILER_BOOTSTRAP_SDK', $body);
        $this->assertStringContainsString('prelinked/bootstrap-gen0/', $body);
        $this->assertStringContainsString('#15602', $body);
    }

    public function testVmInotifyPhpDocAvoidsUnionInsideGenericArrayValue(): void
    {
        $path = self::$root.'/ext/inotify/VmInotify.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringNotContainsString(
            'int|string>>',
            $src,
            'php-types Type::fromDecl() chokes on union inside generic PHPDoc value slot (#18230)'
        );
        $this->assertStringContainsString('list<array<string, mixed>>', $src);
    }

    public function testBootstrapSdkFetchRoundTrip(): void
    {
        $pack = self::$root.'/script/bootstrap-sdk-pack.sh';
        $fetch = self::$root.'/script/bootstrap-sdk-fetch.sh';
        $tmpdir = sys_get_temp_dir().'/phpc-bootstrap-sdk-'.getmypid();
        $this->assertTrue(mkdir($tmpdir) || is_dir($tmpdir));
        $tarball = $tmpdir.'/sdk.tar.gz';
        $extractRoot = $tmpdir.'/extract';
        $this->assertTrue(mkdir($extractRoot));

        $packCmd = 'cd '.escapeshellarg(self::$root).' && bash '.escapeshellarg($pack);
        $proc = proc_open(['bash', '-lc', $packCmd], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $combined = ($stderr !== false ? $stderr : '').($stdout !== false ? $stdout : '');
        $this->assertSame(0, $exit, $combined);
        $this->assertMatchesRegularExpression(
            '/build\/php-compiler-bootstrap-[0-9a-f]{12}\.tar\.gz/',
            $combined
        );
        if (!preg_match('/build\/(php-compiler-bootstrap-[0-9a-f]{12}\.tar\.gz)/', $combined, $m)) {
            $this->fail('pack output missing tarball path');
        }
        $built = self::$root.'/build/'.$m[1];
        $this->assertFileExists($built);
        copy($built, $tarball);

        $fetchCmd = 'export PHP_COMPILER_BOOTSTRAP_SDK_ROOT='.escapeshellarg($extractRoot)
            .'; bash '.escapeshellarg($fetch).' '.escapeshellarg('file://'.$tarball);
        $proc = proc_open(['bash', '-lc', $fetchCmd], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $combined = ($stderr !== false ? $stderr : '').($stdout !== false ? $stdout : '');
        $this->assertSame(0, $exit, $combined);
        $this->assertFileExists($extractRoot.'/prelinked/bootstrap-gen0/bin-compile-aot');
        $this->assertFileExists($extractRoot.'/prelinked/bootstrap-gen0/.m3_compiler_lib_sidecar.sha');

        @unlink($tarball);
        @rmdir($tmpdir);
    }

    public function testPhpcBootstrapInitWiring(): void
    {
        $phpc = (string) file_get_contents(self::$root.'/bin/phpc.php');
        $this->assertStringContainsString("case 'bootstrap':", $phpc);
        $this->assertStringContainsString('bootstrap-init.sh', $phpc);
        $this->assertStringContainsString('--sdk-url', $phpc);
    }

    public function testBootstrapInitTier1DoesNotRequireComposer(): void
    {
        $body = (string) file_get_contents(self::$root.'/script/bootstrap-init.sh');
        $this->assertStringContainsString('WITH_COMPOSER=0', $body);
        $this->assertStringContainsString('skipping composer (Tier 1 only', $body);
        $this->assertStringContainsString('--with-composer', $body);
        $this->assertStringContainsString('#15600', $body);
        $this->assertMatchesRegularExpression(
            '/if \[\[ "\$\{WITH_COMPOSER\}" == "1" \]\]; then/',
            $body,
            'composer install only on --with-composer (#15600)'
        );
    }

    public function testBootstrapNativeLintScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-native-lint.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bin-compile-aot-inventory', $body);
        $this->assertStringContainsString('bin/compile.php', $body);
        $this->assertStringContainsString('#15601', $body);
        $this->assertStringContainsString('zend_lint_fallback', $body);
        $this->assertStringContainsString('PHP_COMPILER_NATIVE_LINT_ZEND_ONLY', $body);
    }

    public function testPhpcLintNativeCompilerSmokeFallback(): void
    {
        $fixture = self::$root.'/test/bootstrap-aot/compiler_smoke.php';
        $this->assertFileExists($fixture);
        $cmd = array_merge(
            self::phpCommand(),
            [self::$root.'/bin/phpc.php', 'lint', '--native', $fixture]
        );
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $combined = ($stderr !== false ? $stderr : '').($stdout !== false ? $stdout : '');
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('OK (Zend fallback)', $combined);
    }

    public function testPhpcLintNativeWiring(): void
    {
        $phpc = (string) file_get_contents(self::$root.'/bin/phpc.php');
        $this->assertStringContainsString('--native', $phpc);
        $this->assertStringContainsString('bootstrap-native-lint.sh', $phpc);
    }

    public function testBootstrapSdkPackScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-sdk-pack.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('php-compiler-bootstrap-', $body);
        $this->assertStringContainsString('linux-x86_64', $body);
        $this->assertStringContainsString('prelinked/bootstrap-gen0', $body);
        $this->assertStringContainsString('#15602', $body);
    }

    public function testMakefileBootstrapSdkPackTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-sdk-pack:', $makefile);
        $this->assertStringContainsString('bootstrap-sdk-pack.sh', $makefile);
    }

    public function testPhpcLintNativeHelloWorld(): void
    {
        $example = self::$root.'/examples/000-HelloWorld/example.php';
        $this->assertFileExists($example);
        $cmd = array_merge(
            self::phpCommand(),
            [self::$root.'/bin/phpc.php', 'lint', '--native', $example]
        );
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, ($stderr !== false ? $stderr : '').($stdout !== false ? $stdout : ''));
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }

        return $cmd;
    }

    private function runBashCheck(string $log): int
    {
        $lib = self::$root.'/script/bootstrap-honest-compile-lib.sh';
        $cmd = 'source '.escapeshellarg($lib)
            .'; bootstrap_honest_compile_log_uses_sidecar_recovery '
            .escapeshellarg($log);
        $proc = proc_open(['bash', '-lc', $cmd], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, self::$root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc);
    }
}
