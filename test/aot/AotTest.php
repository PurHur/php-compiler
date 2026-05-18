<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * End-to-end AOT tests: compile PHP to a native binary via LLVM and run it.
 */
final class AotTest extends BaseTest
{
    protected static string $DIR = __DIR__;

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
        $env = null;
        if (isset($sections['ENV'])) {
            $env = [];
            foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
                if (is_string($value)) {
                    $env[$key] = $value;
                }
            }
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

        $compileCmd = array_merge(
            self::llvmEnvPrefix(),
            $this->phpCommand(),
            [$this->BIN, '-o', $outfile]
        );
        $compile = proc_open(
            $compileCmd,
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
        $compileCode = proc_close($compile);
        if (0 !== $compileCode && !is_executable($outfile)) {
            $this->fail(
                "AOT compile failed for {$name} (exit {$compileCode}): "
                . trim($compileErr !== false ? $compileErr : '')
            );
        }
        if (!is_executable($outfile)) {
            $this->fail("AOT compile did not produce executable: {$outfile}");
        }

        $run = proc_open(
            [$outfile],
            $descriptorSpec,
            $runPipes,
            $repoRoot,
            $env
        );
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        proc_close($run);
        @unlink($outfile);

        $this->assertExpect($result !== false ? $result : '', $sections);
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
