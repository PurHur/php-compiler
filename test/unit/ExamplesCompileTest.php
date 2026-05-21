<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * CI gate: shipped examples compile in VM and (when LLVM is present) AOT lint.
 *
 * @see https://github.com/PurHur/php-compiler/issues/203
 * @see https://github.com/PurHur/php-compiler/issues/243 (structured phpc lint per shipped example)
 * @see https://github.com/PurHur/php-compiler/issues/247 (002-StaticWeb compile.php build + execute)
 * @see https://github.com/PurHur/php-compiler/issues/282 (002-StaticWeb via ./phpc build)
 * @see https://github.com/PurHur/php-compiler/issues/309 (001-SimpleWeb AOT execute + QUERY_STRING refresh in this gate)
 * @see https://github.com/PurHur/php-compiler/issues/259 (001-SimpleWeb POST via $_REQUEST)
 * @see https://github.com/PurHur/php-compiler/issues/274 (minimal phpc.json beside web examples)
 */
final class ExamplesCompileTest extends TestCase
{
    private static ?bool $llvmReady = null;

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideExamples(): array
    {
        $cases = [];
        $root = dirname(__DIR__, 2).'/examples';
        foreach (glob($root.'/*/example.php') ?: [] as $path) {
            $name = basename(dirname($path));
            $cases[$name] = [$path];
        }
        ksort($cases);

        return $cases;
    }

    /**
     * @dataProvider provideExamples
     */
    public function testVmLint(string $examplePath): void
    {
        $name = basename(dirname($examplePath));
        $this->runCli('vm.php', array_merge(self::vmExtraArgs($name), ['-l', $examplePath]));
    }

    /**
     * Structured lint (line + tracking issue) for each shipped example.
     *
     * @dataProvider provideExamples
     *
     * @see https://github.com/PurHur/php-compiler/issues/243
     */
    public function testPhpcLint(string $examplePath): void
    {
        $exit = $this->runLint([$examplePath]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    /**
     * @dataProvider provideExamples
     *
     * @see https://github.com/PurHur/php-compiler/issues/243
     */
    public function testPhpcLintJsonClean(string $examplePath): void
    {
        $exit = $this->runLint(['--json', $examplePath]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('issues', $decoded);
        $this->assertSame([], $decoded['issues']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideWebExampleManifestDirs(): array
    {
        $root = dirname(__DIR__, 2).'/examples';

        return [
            '001-SimpleWeb' => [$root.'/001-SimpleWeb'],
            '002-StaticWeb' => [$root.'/002-StaticWeb'],
            '004-ApiJson' => [$root.'/004-ApiJson'],
        ];
    }

    /**
     * @dataProvider provideWebExampleManifestDirs
     *
     * @see https://github.com/PurHur/php-compiler/issues/274
     */
    public function testWebExamplePhpcJsonEntryExists(string $exampleDir): void
    {
        $manifestPath = $exampleDir.'/phpc.json';
        $this->assertFileExists($manifestPath);
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entry', $decoded);
        $this->assertArrayHasKey('binary', $decoded);
        $this->assertIsString($decoded['entry']);
        $this->assertSame('example.php', $decoded['entry']);
        $this->assertSame('.phpc/bin/app', $decoded['binary']);
        $entryPath = $exampleDir.'/'.$decoded['entry'];
        $this->assertFileExists($entryPath, 'phpc.json entry must exist: '.$entryPath);
    }

    public function testPhpcLintDelegatesViaPhpc(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $example = $repoRoot.'/examples/000-HelloWorld/example.php';
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'lint', $example]
        );
        $exit = $this->runLintCommand($cmd, $repoRoot);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    public function testPhpcLintFailureSurfacesTrackingIssue(): void
    {
        $exit = $this->runLint(['-r', '<?php foreach ([1] as $x) {}']);
        $this->assertSame(1, $exit['code']);
        $combined = $exit['stdout'].$exit['stderr'];
        $this->assertStringContainsString('unsupported', $combined);
        $this->assertStringContainsString('see #53', $combined);
        $this->assertMatchesRegularExpression('/line \d+/', $combined);
    }

    /**
     * @dataProvider provideExamples
     */
    public function testVmSmokeOutput(string $examplePath): void
    {
        $name = basename(dirname($examplePath));
        $out = $this->runCli('vm.php', array_merge(self::vmExtraArgs($name), [$examplePath]));
        foreach (self::smokeNeedles($name) as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
    }

    /**
     * Shipped 001-SimpleWeb: VM run with -p populates $_REQUEST from POST body (issue #259).
     */
    public function testVmSmokePost001SimpleWeb(): void
    {
        $examplePath = dirname(__DIR__, 2).'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($examplePath);
        $out = $this->runCli('vm.php', ['-p', 'name=PostExample', $examplePath]);
        $this->assertStringContainsString('Hello PostExample', $out);
    }

    /**
     * @dataProvider provideExamples
     *
     * @group llvm
     * @group aot
     * @group aot-lint
     */
    public function testAotLint(string $examplePath): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $name = basename(dirname($examplePath));
        $this->runCli('compile.php', array_merge(self::vmExtraArgs($name), ['-l', $examplePath]), true);
    }

    /**
     * Shipped 001-SimpleWeb: build AOT binary without compile-time `-q`, run twice with
     * different QUERY_STRING — catches regressions in runtime superglobal refresh for web binaries.
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testAotExecuteSimpleWebDualQuery(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $source = realpath(dirname(__DIR__, 2).'/examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);

        $envAlice = $env;
        $envAlice['QUERY_STRING'] = 'name=Alice';
        $envAlice['SCRIPT_NAME'] = '/example.php';
        $envAlice['REQUEST_URI'] = '/example.php?name=Alice';
        $outAlice = $this->runAotBinary($binary, $envAlice);
        $this->assertStringContainsString('<h1>Hello Alice</h1>', $outAlice);

        $envBob = $env;
        $envBob['QUERY_STRING'] = 'name=Bob';
        $envBob['SCRIPT_NAME'] = '/example.php';
        $envBob['REQUEST_URI'] = '/example.php?name=Bob';
        $outBob = $this->runAotBinary($binary, $envBob);
        $this->assertStringContainsString('<h1>Hello Bob</h1>', $outBob);

        @unlink($binary);
    }

    /**
     * Shipped 001-SimpleWeb: AOT binary with REQUEST_BODY — $_REQUEST POST path (issue #259).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testAotExecuteSimpleWebPost(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $source = realpath(dirname(__DIR__, 2).'/examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);

        $envPost = $env;
        $envPost['REQUEST_METHOD'] = 'POST';
        $envPost['REQUEST_BODY'] = 'name=PostAot';
        $envPost['SCRIPT_NAME'] = '/example.php';
        $envPost['REQUEST_URI'] = '/example.php';
        $out = $this->runAotBinary($binary, $envPost);
        $this->assertStringContainsString('<h1>Hello PostAot</h1>', $out);

        @unlink($binary);
    }

    /**
     * Shipped 004-ApiJson: compile.php -o temp binary — json_encode + http_response_code(200) AOT smoke.
     *
     * @group llvm
     * @group aot
     * @group aot-link
     *
     * @see https://github.com/PurHur/php-compiler/issues/270
     */
    public function testAotExecuteSmoke004ApiJson(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $source = realpath($repoRoot.'/examples/004-ApiJson/example.php');
        $this->assertNotFalse($source);

        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);
        $out = $this->runAotBinary($binary, $env);
        $this->assertStringContainsString('Content-Type: application/json', $out);
        $this->assertStringContainsString('Status: 200', $out);
        foreach (self::smokeNeedles('004-ApiJson') as $needle) {
            $this->assertStringContainsString($needle, $out);
        }

        @unlink($binary);
    }

    /**
     * Shipped 002-StaticWeb: compile.php -o temp binary, run once — AOT link + runtime smoke (no superglobals).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     *
     * @see https://github.com/PurHur/php-compiler/issues/247
     */
    public function testAotExecuteSmoke002StaticWeb(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $source = realpath($repoRoot.'/examples/002-StaticWeb/example.php');
        $this->assertNotFalse($source);

        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileAotBinaryNoQueryBaking($source, $repoRoot, $env);
        $out = $this->runAotBinary($binary, $env);
        $this->assertStringContainsString('Hello World', $out);

        @unlink($binary);
    }

    /**
     * Shipped 002-StaticWeb: phpc build --project reads phpc.json entry/binary (issue #106).
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testPhpcBuildProject002StaticWeb(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $exampleDir = realpath($repoRoot.'/examples/002-StaticWeb');
        $this->assertNotFalse($exampleDir);

        $phpc = realpath($repoRoot.'/phpc');
        $this->assertNotFalse($phpc);
        $env = $this->llvmProcessEnv($repoRoot);

        $binaryPath = $exampleDir.'/.phpc/bin/app';
        if (is_file($binaryPath)) {
            unlink($binaryPath);
        }
        $binDir = dirname($binaryPath);
        if (!is_dir($binDir)) {
            mkdir($binDir, 0777, true);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$phpc, 'build', '--project', $exampleDir], $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));
        $this->assertFileExists($binaryPath);
        $this->assertTrue(is_executable($binaryPath));

        $out = $this->runAotBinary($binaryPath, $env);
        $this->assertStringContainsString('Hello World', $out);

        @unlink($binaryPath);
    }

    /**
     * Shipped 002-StaticWeb: ./phpc build then execute — smoke for unified CLI argv/env forwarding.
     *
     * @group llvm
     * @group aot
     * @group aot-link
     */
    public function testPhpcBuildSmoke002StaticWeb(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $repoRoot = dirname(__DIR__, 2);
        $source = realpath($repoRoot.'/examples/002-StaticWeb/example.php');
        $this->assertNotFalse($source);

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_phpc_build_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $phpc = realpath($repoRoot.'/phpc');
        $this->assertNotFalse($phpc);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->phpcBuildBinary($phpc, $outfile, $source, $repoRoot, $env);

        $out = $this->runAotBinary($binary, $env);
        $this->assertStringContainsString('Hello World', $out);

        @unlink($binary);
    }

    /**
     * @param array<string, string> $env
     */
    private function phpcBuildBinary(string $phpc, string $outfile, string $source, string $repoRoot, array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$phpc, 'build', '-o', $outfile, $source], $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'phpc build failed for '.$source
        );
        $this->assertFileExists($outfile, trim($stderr !== false ? $stderr : ''));
        $this->assertTrue(is_executable($outfile));

        return $outfile;
    }

    /**
     * @param array<string, string> $env
     */
    private function compileAotBinaryNoQueryBaking(string $source, string $repoRoot, array $env): string
    {
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_ex_gate_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $bin = realpath($repoRoot.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $cmd = array_merge(
            self::llvmEnvPrefix(),
            self::phpCommand(),
            [$bin, '-o', $outfile, $source]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php failed for '.$source
        );
        $this->assertFileExists($outfile, trim($stderr !== false ? $stderr : ''));
        $this->assertTrue(is_executable($outfile));

        return $outfile;
    }

    /**
     * @param array<string, string> $env
     */
    private function runAotBinary(string $binary, array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $run = proc_open([$binary], $descriptorSpec, $pipes, null, $env);
        $this->assertIsResource($run);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($run);
        $this->assertSame(0, $exitCode, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }

    /**
     * @param list<string> $lintArgs arguments after bin/lint.php
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLint(array $lintArgs): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/lint.php'], $lintArgs);

        return $this->runLintCommand($cmd, $repoRoot);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLintCommand(array $cmd, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }

    /**
     * @param list<string> $argvArgs arguments after the bin script path
     */
    private function runCli(string $binScript, array $argvArgs, bool $llvm = false): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $bin = realpath($repoRoot.'/bin/'.$binScript);
        $this->assertNotFalse($bin);
        $cmd = array_merge(
            $llvm ? self::llvmEnvPrefix() : [],
            self::phpCommand(),
            array_merge([$bin], $argvArgs)
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $llvm ? $this->llvmProcessEnv($repoRoot) : null;
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : ''))
        );

        return $stdout !== false ? $stdout : '';
    }

    /**
     * @return list<string>
     */
    private static function vmExtraArgs(string $exampleName): array
    {
        if ('001-SimpleWeb' === $exampleName) {
            return ['-q', 'name=Example'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function smokeNeedles(string $exampleName): array
    {
        return match ($exampleName) {
            '000-HelloWorld' => ['Hello World'],
            '001-SimpleWeb' => ['Hello Example'],
            '002-StaticWeb' => ['Hello World'],
            '004-ApiJson' => ['"ok":true', 'php-compiler'],
            default => ['Hello'],
        };
    }

    /**
     * @return array<string, string>
     */
    private function llvmProcessEnv(string $repoRoot): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        return $env;
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
        $cmd[] = '-d';
        $cmd[] = 'display_errors=0';
        $cmd[] = '-d';
        $cmd[] = 'error_reporting=0';

        return $cmd;
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }

    /**
     * @return list<string>
     */
    private static function llvmEnvPrefix(): array
    {
        return LlvmToolchain::envPrefix(dirname(__DIR__, 2));
    }
}
