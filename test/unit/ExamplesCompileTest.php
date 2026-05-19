<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * CI gate: shipped examples compile in VM and (when LLVM is present) AOT lint.
 *
 * @see https://github.com/PurHur/php-compiler/issues/203
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
