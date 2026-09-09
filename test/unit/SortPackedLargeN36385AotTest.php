<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Large packed sort() must match Zend under AOT without SIGSEGV (#36385).
 *
 * @group llvm
 * @group aot
 */
final class SortPackedLargeN36385AotTest extends TestCase
{
    public function testLargePackedSortMatchesZendOnAot(): void
    {
        $path = __DIR__.'/../repro/sort_packed_large_n_36385.php';
        $zend = $this->runZendFile($path);
        $this->assertNotSame('', $zend);
        $this->assertSame($zend, $this->compileAndRunAot($path), 'AOT must match Zend (no stack blow from loop allocas)');
    }

    public function testSortPackedHoistsLoopScratchAllocas(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $start = strpos($src, 'private function implementSortPacked(');
        $this->assertNotFalse($start);
        $next = strpos($src, 'private function implementSortPackedNatural(', $start + 1);
        $this->assertNotFalse($next);
        $body = substr($src, $start, $next - $start);
        // Scratch allocas must be created once in the work entry, not per walk/swap.
        $this->assertStringContainsString("alloca(\$valueType, 1, \$tag.'_tmp')", $body);
        $this->assertStringContainsString('#36385', $body);
        $swapPos = strpos($body, "positionAtEnd(\$swapBlock)");
        $this->assertNotFalse($swapPos);
        $afterSwap = substr($body, $swapPos);
        $this->assertStringNotContainsString(
            "alloca(\$valueType, 1, \$tag.'_tmp')",
            $afterSwap,
            'tmp alloca must not live inside swapBlock'
        );
    }

    private function runZendFile(string $path): string
    {
        $php = getenv('PHP_8_2') ?: (getenv('PHP_BINARY') ?: PHP_BINARY);
        $out = shell_exec(escapeshellarg($php).' '.escapeshellarg($path).' 2>&1');

        return is_string($out) ? $out : '';
    }

    private function compileAndRunAot(string $path): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'aot36385sort_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        $cache = sys_get_temp_dir().'/hr36385sort_'.getmypid();
        @mkdir($cache, 0777, true);
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache)
            .' php '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
        exec($compile, $clog, $crc);
        $this->assertSame(0, $crc, "compile failed:\n".implode("\n", $clog));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "aot run failed (rc=$rc):\n".implode("\n", $out));

            return implode("\n", $out).([] !== $out ? "\n" : '');
        } finally {
            @unlink($bin);
        }
    }
}
