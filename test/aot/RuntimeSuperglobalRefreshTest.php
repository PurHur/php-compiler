<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT binaries refresh superglobals from CGI env each run (issue #201).
 *
 * @group llvm
 */
final class RuntimeSuperglobalRefreshTest extends TestCase
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

    public function testTwoRequestsDifferentQueryString(): void
    {
        $source = realpath(__DIR__ . '/../../examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_refresh_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile, $source]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $envA = $env;
        $envA['QUERY_STRING'] = 'name=Alice';
        $envA['SCRIPT_NAME'] = '/example.php';
        $envA['REQUEST_URI'] = '/example.php?name=Alice';
        $outA = $this->runBinary($outfile, $envA);
        $this->assertStringContainsString('Hello Alice', $outA);

        $envB = $env;
        $envB['QUERY_STRING'] = 'name=Bob';
        $envB['SCRIPT_NAME'] = '/example.php';
        $envB['REQUEST_URI'] = '/example.php?name=Bob';
        $outB = $this->runBinary($outfile, $envB);
        $this->assertStringContainsString('Hello Bob', $outB);

        @unlink($outfile);
    }

    public function testHttpHostFromCgiEnvironment(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_CUSTOM'];
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_http_hdr_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $runEnv = $env;
        $runEnv['HTTP_HOST'] = 'example.test';
        $runEnv['HTTP_X_CUSTOM'] = '1';
        $runEnv['SCRIPT_NAME'] = '/index.php';
        $runEnv['REQUEST_URI'] = '/index.php';
        $output = $this->runBinary($outfile, $runEnv);
        $this->assertStringContainsString('example.test1', $output);

        @unlink($outfile);
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
        $llvmDir = $repoRoot.'/.llvm';
        if (is_file($llvmDir.'/libLLVM-9.so.1')) {
            $prefix = realpath($llvmDir) ?: $llvmDir;
            $env['PHP_COMPILER_LLVM_PATH'] = $prefix;
            $ld = $env['LD_LIBRARY_PATH'] ?? '';
            $env['LD_LIBRARY_PATH'] = '' === $ld ? $prefix : $prefix.':'.$ld;
            $path = $env['PATH'] ?? '';
            $env['PATH'] = '' === $path ? $prefix : $prefix.':'.$path;
        }

        return $env;
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        $llvmDir = dirname(__DIR__, 2).'/.llvm';
        if (!is_file($llvmDir.'/libLLVM-9.so.1')) {
            self::$llvmReady = false;

            return false;
        }
        if ('' === getenv('PHP_COMPILER_LLVM_PATH')) {
            putenv('PHP_COMPILER_LLVM_PATH='.$llvmDir);
        }
        try {
            \PHPLLVM\Chooser::choose();
            self::$llvmReady = true;
        } catch (\Throwable $e) {
            self::$llvmReady = false;
        }

        return self::$llvmReady;
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

        return $cmd;
    }

    /**
     * @return list<string>
     */
    private static function llvmEnvPrefix(): array
    {
        $llvmDir = dirname(__DIR__, 2).'/.llvm';
        if (!is_file($llvmDir.'/libLLVM-9.so.1')) {
            return [];
        }
        $prefix = realpath($llvmDir) ?: $llvmDir;
        $ld = getenv('LD_LIBRARY_PATH');
        $ldVal = false === $ld || '' === $ld ? $prefix : $prefix.':'.$ld;
        $path = getenv('PATH');
        $pathVal = false === $path || '' === $path ? $prefix : $prefix.':'.$path;

        return [
            'env',
            'LD_LIBRARY_PATH='.$ldVal,
            'PATH='.$pathVal,
            'PHP_COMPILER_LLVM_PATH='.$prefix,
        ];
    }
}
