<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Config registry + phpc doctor --env (#36201).
 *
 * Call sites in lib/ and bin/ use Config::getenv() for PHP_COMPILER_* (#36201 follow-up).
 * Config.php is on the self-host spine (required before Doctor.php).
 */
final class ConfigRegistryTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testRegistryContainsMemoryLimits(): void
    {
        $reg = Config::registry();
        $this->assertArrayHasKey('PHP_COMPILER_MEMORY_LIMIT', $reg);
        $this->assertArrayHasKey('PHP_COMPILER_LLVM_MEMORY_LIMIT', $reg);
        $this->assertSame('8192M', $reg['PHP_COMPILER_LLVM_MEMORY_LIMIT']['default']);
        $this->assertSame('1536M', $reg['PHP_COMPILER_MEMORY_LIMIT']['default']);
    }

    public function testGetenvDropInMatchesUnset(): void
    {
        $name = 'PHP_COMPILER_OPT_LEVEL';
        $prev = getenv($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        try {
            $this->assertFalse(Config::getenv($name));
            putenv($name.'=2');
            $this->assertSame('2', Config::getenv($name));
        } finally {
            if (false === $prev) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$prev);
                $_ENV[$name] = $prev;
            }
        }
    }

    public function testDockerfileNoLongerDriftsFromCiDefaults(): void
    {
        $drift = Config::dockerfileCiDefaultsDrift($this->repoRoot());
        $llvm = array_values(array_filter(
            $drift,
            static fn (array $d): bool => 'PHP_COMPILER_LLVM_MEMORY_LIMIT' === $d['name']
        ));
        $this->assertSame([], $llvm, 'Dockerfile LLVM memory must match ci-defaults 8192M (#36201)');
    }

    public function testDoctorEnvExitsZeroAfterDockerfileSync(): void
    {
        $repoRoot = $this->repoRoot();
        $cmd = [
            PHP_BINARY,
            $repoRoot.'/bin/phpc.php',
            'doctor',
            '--env',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot, [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ]);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $stdout."\n".$stderr);
        $this->assertStringContainsString('PHP_COMPILER_LLVM_MEMORY_LIMIT', (string) $stdout);
        $this->assertStringContainsString('Dockerfile ↔ ci-defaults.env: OK', (string) $stdout);
    }

    public function testGetFallsBackToRegistryDefault(): void
    {
        $name = 'PHP_COMPILER_LLVM_MEMORY_LIMIT';
        $prev = getenv($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        try {
            $this->assertSame('8192M', Config::get($name));
        } finally {
            if (false === $prev) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$prev);
                $_ENV[$name] = $prev;
            }
        }
    }

    public function testLibBinNoRawPhpCompilerGetenv(): void
    {
        $root = $this->repoRoot();
        $left = [];
        foreach (['lib', 'bin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir));
            foreach ($it as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                if ('Config.php' === $file->getFilename()) {
                    continue;
                }
                $path = $file->getPathname();
                $lines = file($path) ?: [];
                foreach ($lines as $i => $line) {
                    if (!str_contains($line, 'PHP_COMPILER_') || !str_contains($line, 'getenv')) {
                        continue;
                    }
                    if (str_contains($line, 'Config::getenv')) {
                        continue;
                    }
                    if (str_contains($line, '{@see getenv()')) {
                        continue;
                    }
                    if (preg_match("/getenv\\s*\\(\\s*['\"]PHP_COMPILER_/", $line)) {
                        $left[] = $path.':'.($i + 1);
                    }
                }
            }
        }
        $this->assertSame([], $left, 'raw getenv(PHP_COMPILER_*) must go through Config::getenv (#36201)');
    }

    public function testConfigOnSelfHostSpine(): void
    {
        $spine = (string) file_get_contents($this->repoRoot().'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString(
            "require_once __DIR__.'/../../../lib/Config.php';",
            $spine,
            'Config.php must be on compiler_lib_spine_smoke (#36201)'
        );
    }
}
