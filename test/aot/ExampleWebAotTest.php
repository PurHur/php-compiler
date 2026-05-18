<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT-compile shipped web examples from disk (not stdin PHPT).
 *
 * @group llvm
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
        $result = $this->compileAndRun($source, ['-q', 'name=Example']);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $result);
        $this->assertStringContainsString('<h1>Hello Example</h1>', $result);
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
     * @param list<string> $compileExtraArgs e.g. ['-q', 'name=Example']
     */
    private function compileAndRun(string $source, array $compileExtraArgs): string
    {
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_web_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);

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

        $run = proc_open([$outfile], $descriptorSpec, $runPipes, $repoRoot, $env);
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $exitCode = proc_close($run);
        $this->assertSame(0, $exitCode, 'AOT binary should exit with status 0');
        @unlink($outfile);

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
