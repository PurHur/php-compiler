<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * CI gate: shipped examples compile in VM and (when LLVM is present) AOT lint.
 *
 * @see https://github.com/PurHur/php-compiler/issues/203
 * @see https://github.com/PurHur/php-compiler/issues/309 (001-SimpleWeb AOT execute + QUERY_STRING refresh in this gate)
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
     * @dataProvider provideExamples
     *
     * @group llvm
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
