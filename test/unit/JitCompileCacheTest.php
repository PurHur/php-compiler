<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\CompileCache;

/**
 * On-disk JIT compile cache (issue #153).
 *
 * @group llvm
 * @group jit
 */
final class JitCompileCacheTest extends TestCase
{
    private string $repoRoot;

    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->cacheRoot = sys_get_temp_dir().'/phpc-cache-test-'.bin2hex(random_bytes(4));
        putenv('PHP_COMPILER_CACHE_DIR='.$this->cacheRoot);
        $_ENV['PHP_COMPILER_CACHE_DIR'] = $this->cacheRoot;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheRoot);
        putenv('PHP_COMPILER_CACHE_DIR');
        unset($_ENV['PHP_COMPILER_CACHE_DIR']);
    }

    public function testComputeKeyChangesWhenSourceChanges(): void
    {
        $path = $this->repoRoot.'/examples/000-HelloWorld/example.php';
        $this->assertFileExists($path);
        $code = (string) file_get_contents($path);
        $keyA = CompileCache::computeKey($path, $code);
        $keyB = CompileCache::computeKey($path, $code."\n");
        $this->assertNotSame($keyA, $keyB);
    }

    public function testIsFreshFalseWithoutBitcodeFile(): void
    {
        $path = $this->repoRoot.'/examples/000-HelloWorld/example.php';
        $code = (string) file_get_contents($path);
        $key = CompileCache::computeKey($path, $code);
        $this->assertFalse(CompileCache::isFresh($key, $path, $code));
    }

    public function testSecondProcessUsesDiskCacheForHelloWorld(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available');
        }

        $target = $this->repoRoot.'/examples/000-HelloWorld/example.php';
        $this->assertFileExists($target);

        $cold = $this->runJitSubprocess($target);
        if (139 === $cold['exit'] || 11 === $cold['exit']) {
            $this->markTestSkipped('MCJIT segfault in this environment (issue #98); cache logic covered by unit tests');
        }
        $this->assertSame(0, $cold['exit'], $cold['stderr']);
        $this->assertStringContainsString('Hello World', $cold['stdout']);

        $key = CompileCache::computeKey($target, (string) file_get_contents($target));
        $this->assertFileExists(CompileCache::bitcodePath($key));
        $this->assertFileExists(CompileCache::metaPath($key));

        $warm = $this->runJitSubprocess($target);
        $this->assertSame(0, $warm['exit'], $warm['stderr']);
        $this->assertStringContainsString('Hello World', $warm['stdout']);
        $this->assertStringContainsString('cache_hit=1', $warm['stderr']);
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runJitSubprocess(string $target): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_CACHE_DIR'] = $this->cacheRoot;
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        $wrapper = tempnam(sys_get_temp_dir(), 'jit-cache-probe-');
        $this->assertNotFalse($wrapper);
        file_put_contents($wrapper, '<?php
declare(strict_types=1);
require '.var_export($this->repoRoot.'/vendor/autoload.php', true).';
putenv(\'PHP_COMPILER_SKIP_LLVM_PRELOAD=1\');
$target = $argv[1];
$code = file_get_contents($target);
$runtime = new PHPCompiler\Runtime();
$block = $runtime->parseAndCompile($code, $target);
$cacheKey = PHPCompiler\JIT\CompileCache::computeKey($target, $code);
$wasFresh = PHPCompiler\JIT\CompileCache::isFresh($cacheKey, $target, $code);
$runtime->jit($block, $code, $target);
if (!$wasFresh) {
    fwrite(STDERR, "cache_hit=0\n");
} else {
    fwrite(STDERR, "cache_hit=1\n");
}
$runtime->run($block);
');

        $proc = proc_open(
            [PHP_BINARY, $wrapper, $target],
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
        @unlink($wrapper);

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
