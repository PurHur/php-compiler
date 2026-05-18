<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * End-to-end AOT tests: compile PHP to a native binary via LLVM and run it.
 *
 * @group llvm
 */
final class AotTest extends BaseTest
{
    protected static string $DIR = __DIR__ . '/../fixtures/aot';

    private static ?bool $llvmReady = null;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/compile.php');
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
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
            $_ENV['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
            $_SERVER['PHP_COMPILER_LLVM_PATH'] = $llvmDir;
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
     * @dataProvider providePHPTests
     */
    public function testCases(string $name, string $code, array $sections): void
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'phpc_aot_');
        if (false === $tmpBase) {
            $this->fail('Could not create temp file');
        }
        unlink($tmpBase);
        $outfile = $tmpBase;

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $llvmDir = dirname(__DIR__, 2).'/.llvm';
        if (is_file($llvmDir.'/libLLVM-9.so.1')) {
            $prefix = realpath($llvmDir) ?: $llvmDir;
            $env['PHP_COMPILER_LLVM_PATH'] = $prefix;
            $ld = $env['LD_LIBRARY_PATH'] ?? '';
            $env['LD_LIBRARY_PATH'] = '' === $ld ? $prefix : $prefix.':'.$ld;
            $path = $env['PATH'] ?? '';
            $env['PATH'] = '' === $path ? $prefix : $prefix.':'.$path;
        }
        if (isset($sections['ENV'])) {
            foreach (explode("\n", trim($sections['ENV'])) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (2 !== count($parts)) {
                    throw new \LogicException("Invalid ENV line: {$line}");
                }
                $env[$parts[0]] = $parts[1];
            }
        }
        $runEnv = $env;

        $compileArgv = [$this->BIN, '-o', $outfile];
        if (isset($sections['ENV'])) {
            foreach (explode("\n", trim($sections['ENV'])) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (2 === count($parts) && 'QUERY_STRING' === $parts[0]) {
                    $compileArgv[] = '-q';
                    $compileArgv[] = $parts[1];
                    break;
                }
            }
        }

        $compile = proc_open(
            array_merge(self::llvmEnvPrefix(), $this->phpCommand(), $compileArgv),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        if (!is_executable($outfile)) {
            $this->fail(
                "AOT compile did not produce executable {$outfile}: "
                . trim($compileErr !== false ? $compileErr : '')
            );
        }

        $runArgv = [$outfile];
        if (isset($sections['ENV'])) {
            $runArgv = array_merge(self::llvmEnvPrefix(), $runArgv);
            foreach (explode("\n", trim($sections['ENV'])) as $line) {
                $line = trim($line);
                if ('' !== $line) {
                    array_splice($runArgv, -1, 0, [$line]);
                }
            }
        }
        $run = proc_open(
            $runArgv,
            $descriptorSpec,
            $runPipes,
            $repoRoot,
            $runEnv
        );
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $exitCode = proc_close($run);
        @unlink($outfile);

        if (isset($sections['EXPECT_EXIT'])) {
            $this->assertSame((int) trim($sections['EXPECT_EXIT']), $exitCode);
        }
        $this->assertExpect($result !== false ? $result : '', $sections);
    }

}
