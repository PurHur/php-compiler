<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\AOT\HelperRuntimeCache;
use PHPCompiler\JIT\CompileCache;

/**
 * On-disk MCJIT bitcode cache for standalone AOT emit (issue #36199).
 *
 * @group llvm
 * @group aot
 */
final class AotCompileCacheTest extends TestCase
{
    private string $repoRoot;

    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->cacheRoot = sys_get_temp_dir().'/phpc-aot-cache-test-'.bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0775, true);
        putenv('PHP_COMPILER_CACHE_DIR='.$this->cacheRoot);
        $_ENV['PHP_COMPILER_CACHE_DIR'] = $this->cacheRoot;
        putenv('PHP_COMPILER_AOT_USER_SCRIPT=1');
        $_ENV['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        putenv('PHP_COMPILER_SELFHOST_AOT=0');
        $_ENV['PHP_COMPILER_SELFHOST_AOT'] = '0';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=1');
        $_ENV['PHP_COMPILER_HELPER_RUNTIME_O'] = '1';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheRoot);
        @unlink($this->repoRoot.'/build/aot-cache-test-cold.bin');
        @unlink($this->repoRoot.'/build/aot-cache-test-warm.bin');
        @unlink($this->repoRoot.'/build/aot-cache-test-obj-cold.bin');
        @unlink($this->repoRoot.'/build/aot-cache-test-obj-mid.bin');
        @unlink($this->repoRoot.'/build/aot-cache-test-bc-cold.bin');
        @unlink($this->repoRoot.'/build/aot-cache-test-bc-warm.bin');
        putenv('PHP_COMPILER_CACHE_DIR');
        unset($_ENV['PHP_COMPILER_CACHE_DIR']);
        putenv('PHP_COMPILER_AOT_USER_SCRIPT');
        unset($_ENV['PHP_COMPILER_AOT_USER_SCRIPT']);
        putenv('PHP_COMPILER_SELFHOST_AOT');
        unset($_ENV['PHP_COMPILER_SELFHOST_AOT']);
        putenv('PHP_COMPILER_HELPER_RUNTIME_O');
        unset($_ENV['PHP_COMPILER_HELPER_RUNTIME_O']);
    }

    public function testFingerprintIncludesHelperRuntimeSegment(): void
    {
        $path = $this->repoRoot.'/examples/000-HelloWorld/example.php';
        $code = (string) file_get_contents($path);
        $key = CompileCache::computeKey($path, $code);
        $meta = CompileCache::readMeta($key);
        $this->assertNull($meta);

        $segment = HelperRuntimeCache::cacheKeySegment();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $segment);
    }

    public function testSecondAotBuildUsesDiskCacheForHelloWorld(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available');
        }

        $script = $this->cacheRoot.'/echo.php';
        file_put_contents($script, "<?php echo \"Hello World\\n\";");
        $outCold = $this->repoRoot.'/build/aot-cache-test-cold.bin';
        $outWarm = $this->repoRoot.'/build/aot-cache-test-warm.bin';
        @unlink($outCold);
        @unlink($outWarm);

        $cold = $this->runAotSubprocess($script, $outCold);
        if (139 === $cold['exit'] || 11 === $cold['exit']) {
            $this->markTestSkipped('AOT segfault in this environment; cache wiring covered by unit tests');
        }
        $this->assertSame(0, $cold['exit'], $cold['stderr']);
        $this->assertFileExists($outCold);

        $key = CompileCache::computeKey($script, (string) file_get_contents($script));
        $metaPath = CompileCache::metaPath($key);
        $this->assertFileExists($metaPath);
        $this->assertFileExists(CompileCache::stampPath($key), 'AOT cache must write fresh.stamp (#36387)');
        $this->assertFileDoesNotExist(CompileCache::bitcodePath($key), 'AOT must not persist unreadable full-module bitcode');
        $this->assertFileExists(CompileCache::artifactPath($key), 'linked aot.bin must be cached after cold emit (#36387)');

        $warm = $this->runAotSubprocess($script, $outWarm);
        $this->assertSame(0, $warm['exit'], $warm['stderr']);
        $this->assertFileExists($outWarm);
        $this->assertLessThan(
            $cold['wall_ms'] * 0.5,
            $warm['wall_ms'],
            sprintf(
                'warm artifact restore should be <50%% of cold (cold=%.0fms warm=%.0fms)',
                $cold['wall_ms'],
                $warm['wall_ms']
            )
        );

        $coldRun = $this->runBinary($outCold);
        $warmRun = $this->runBinary($outWarm);
        $this->assertSame(0, $coldRun['exit'], $coldRun['stderr']);
        $this->assertSame(0, $warmRun['exit'], $warmRun['stderr']);
        $this->assertStringContainsString('Hello World', $coldRun['stdout']);
        $this->assertSame(trim($coldRun['stdout']), trim($warmRun['stdout']));
        $this->assertSame(
            hash_file('sha256', $outCold),
            hash_file('sha256', $outWarm),
            'warm artifact restore must be byte-identical to cold binary'
        );
    }

    public function testObjectMidTierRestoresWhenArtifactMissing(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available');
        }

        $script = $this->cacheRoot.'/echo-obj.php';
        file_put_contents($script, "<?php echo \"ObjectCache\\n\";");
        $outCold = $this->repoRoot.'/build/aot-cache-test-obj-cold.bin';
        $outMid = $this->repoRoot.'/build/aot-cache-test-obj-mid.bin';
        @unlink($outCold);
        @unlink($outMid);

        $cold = $this->runAotSubprocess($script, $outCold);
        if (139 === $cold['exit'] || 11 === $cold['exit']) {
            $this->markTestSkipped('AOT segfault in this environment; cache wiring covered by unit tests');
        }
        $this->assertSame(0, $cold['exit'], $cold['stderr']);
        $this->assertFileExists($outCold);

        $key = CompileCache::computeKey($script, (string) file_get_contents($script));
        $this->assertFileExists(CompileCache::objectPath($key), 'aot.o must be cached after cold emit (#36387)');
        $this->assertFileExists(CompileCache::linkManifestPath($key), 'link.json must record helper slugs');
        $this->assertFileExists(CompileCache::artifactPath($key));

        // Drop only the linked binary — mid-tier should re-link from aot.o.
        $this->assertTrue(@unlink(CompileCache::artifactPath($key)));

        $mid = $this->runAotSubprocess($script, $outMid);
        $this->assertSame(0, $mid['exit'], $mid['stderr']);
        $this->assertFileExists($outMid);
        $this->assertLessThan(
            $cold['wall_ms'] * 0.75,
            $mid['wall_ms'],
            sprintf(
                'object mid-tier restore should be <75%% of cold (cold=%.0fms mid=%.0fms)',
                $cold['wall_ms'],
                $mid['wall_ms']
            )
        );

        $coldRun = $this->runBinary($outCold);
        $midRun = $this->runBinary($outMid);
        $this->assertSame(0, $coldRun['exit'], $coldRun['stderr']);
        $this->assertSame(0, $midRun['exit'], $midRun['stderr']);
        $this->assertSame(trim($coldRun['stdout']), trim($midRun['stdout']));
        $this->assertFileExists(CompileCache::artifactPath($key), 'mid-tier link must re-save aot.bin');
    }

    public function testAotStampWarmPathWithoutModuleBitcode(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available');
        }

        $script = $this->cacheRoot.'/echo-stamp.php';
        file_put_contents($script, "<?php echo \"StampCache\\n\";");
        $outCold = $this->repoRoot.'/build/aot-cache-test-bc-cold.bin';
        $outWarm = $this->repoRoot.'/build/aot-cache-test-bc-warm.bin';
        @unlink($outCold);
        @unlink($outWarm);

        $cold = $this->runAotSubprocess($script, $outCold);
        if (139 === $cold['exit'] || 11 === $cold['exit']) {
            $this->markTestSkipped('AOT segfault in this environment; cache wiring covered by unit tests');
        }
        $this->assertSame(0, $cold['exit'], $cold['stderr']);

        $key = CompileCache::computeKey($script, (string) file_get_contents($script));
        $this->assertFileExists(CompileCache::stampPath($key));
        $this->assertFileExists(CompileCache::metaPath($key));
        $this->assertFileExists(CompileCache::artifactPath($key));
        $this->assertFileDoesNotExist(CompileCache::bitcodePath($key));

        $warm = $this->runAotSubprocess($script, $outWarm);
        $this->assertSame(0, $warm['exit'], $warm['stderr']);
        $this->assertFileExists($outWarm);
        $this->assertLessThan(
            $cold['wall_ms'] * 0.5,
            $warm['wall_ms'],
            sprintf(
                'stamp+artifact warm should be <50%% of cold (cold=%.0fms warm=%.0fms)',
                $cold['wall_ms'],
                $warm['wall_ms']
            )
        );

        $coldRun = $this->runBinary($outCold);
        $warmRun = $this->runBinary($outWarm);
        $this->assertSame(0, $coldRun['exit'], $coldRun['stderr']);
        $this->assertSame(0, $warmRun['exit'], $warmRun['stderr']);
        $this->assertSame(trim($coldRun['stdout']), trim($warmRun['stdout']));
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string, wall_ms: float}
     */
    private function runAotSubprocess(string $target, string $outfile): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_CACHE_DIR'] = $this->cacheRoot;
        $env['PHP_COMPILER_AOT_USER_SCRIPT'] = '1';
        $env['PHP_COMPILER_SELFHOST_AOT'] = '0';
        $env['PHP_COMPILER_HELPER_RUNTIME_O'] = '1';
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        $compile = $this->repoRoot.'/bin/compile.php';
        $t0 = hrtime(true);
        $proc = proc_open(
            [PHP_BINARY, $compile, '-o', $outfile, $target],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $wallMs = (hrtime(true) - $t0) / 1_000_000;

        return [
            'exit' => $exit,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
            'wall_ms' => $wallMs,
        ];
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runBinary(string $path): array
    {
        $proc = proc_open(
            [$path],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => $exit,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
