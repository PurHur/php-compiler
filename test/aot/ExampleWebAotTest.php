<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT-compile shipped web examples from disk (not stdin PHPT).
 *
 * @group llvm
 * @group aot
 */
final class ExampleWebAotTest extends TestCase
{
    private static ?bool $llvmReady = null;

    private string $compileBin = '';

    public function setUp(): void
    {
        $this->compileBin = realpath(__DIR__ . '/../../bin/compile.php');
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    public function testSimpleWebExampleFile(): void
    {
        $source = realpath(__DIR__ . '/../../examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileToBinary($source, [], $repoRoot, $env);

        $envAlice = $env;
        $envAlice['QUERY_STRING'] = 'name=Alice';
        $envAlice['SCRIPT_NAME'] = '/example.php';
        $envAlice['REQUEST_URI'] = '/example.php?name=Alice';
        $outAlice = $this->runBinary($binary, $envAlice);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $outAlice);
        $this->assertStringContainsString('<h1>Hello Alice</h1>', $outAlice);

        $envBob = $env;
        $envBob['QUERY_STRING'] = 'name=Bob';
        $envBob['SCRIPT_NAME'] = '/example.php';
        $envBob['REQUEST_URI'] = '/example.php?name=Bob';
        $outBob = $this->runBinary($binary, $envBob);
        $this->assertStringContainsString('<h1>Hello Bob</h1>', $outBob);

        @unlink($binary);
    }

    public function testStaticWebExampleFile(): void
    {
        $source = realpath(__DIR__ . '/../../examples/002-StaticWeb/example.php');
        $this->assertNotFalse($source);
        $result = $this->compileAndRun($source, []);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $result);
        $this->assertStringContainsString('<h1>Hello World</h1>', $result);
    }

    /**
     * @param list<string> $compileExtraArgs
     */
    private function compileToBinary(string $source, array $compileExtraArgs, string $repoRoot, array $env): string
    {
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_web_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $compileArgv = array_merge(
            self::llvmEnvPrefix(),
            self::phpCommand(),
            array_merge([$this->compileBin], $compileExtraArgs, ['-o', $outfile, $source])
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);

        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));
        $this->assertTrue(is_executable($outfile));

        return $outfile;
    }

    /**
     * @param array<string, string> $env
     */
    private function runBinary(string $binary, array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $run = proc_open([$binary], $descriptorSpec, $pipes, null, $env);
        $result = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($run);
        $this->assertSame(0, $exitCode, 'AOT binary should exit with status 0');

        return $result !== false ? $result : '';
    }

    /**
     * @param list<string> $compileExtraArgs
     */
    private function compileAndRun(string $source, array $compileExtraArgs): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $binary = $this->compileToBinary($source, $compileExtraArgs, $repoRoot, $env);
        $result = $this->runBinary($binary, $env);
        @unlink($binary);

        return $result;
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
            $cmd = preg_split('/\s+/', $phpEnv);
        } else {
            $cmd = [PHP_BINARY];
        }
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
