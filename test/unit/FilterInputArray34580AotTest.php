<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT filter_input_array must return NULL on CLI, not abort (#34580).
 *
 * @see php-src ext/filter/filter.c — php_filter_input_array
 *
 * @group llvm
 * @group aot
 */
final class FilterInputArray34580AotTest extends TestCase
{
    public function testReproFixtureMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/issue_34580_filter_input_array_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testHelperReturnsNullableHashTable(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/filter/FilterBatchJitHelper.php');
        $this->assertStringContainsString('): ?HashTable', $helper);
        $this->assertStringContainsString('#34580', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/FilterInputArrayRuntime.php');
        $this->assertStringContainsString('boxHashtableOrNull', $runtime);
        $this->assertStringContainsString('#34580', $runtime);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir().'/fia_34580_'.getmypid().'_'.mt_rand();
        mkdir($dir);
        $bin = $dir.'/t.bin';
        $env = getenv();
        if (!\is_array($env)) {
            $env = [];
        }
        $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = $dir.'/helper-cache';
        $env['PHP_COMPILER_HELPER_RUNTIME_O'] = '0';
        mkdir($env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR']);
        $cmd = [
            PHP_BINARY,
            '-d', 'memory_limit=512M',
            $root.'/bin/compile.php',
            '-o', $bin,
            $src,
        ];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $this->assertSame(0, $rc, "compile failed: $stdout$stderr");
        $this->assertFileExists($bin);
        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        $this->assertSame(0, $runRc, 'run failed: '.implode("\n", $out));

        return implode("\n", $out)."\n";
    }
}
