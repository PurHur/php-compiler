<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Builtin\MemoryManager\Native;
use PHPUnit\Framework\TestCase;

/**
 * Request bump-arena for thin standalone AOT (#36388).
 */
final class Issue36388ArenaAotTest extends TestCase
{
    public function testNativeExposesArenaAbi(): void
    {
        $this->assertSame('__phpc_mm_arena_release', Native::ARENA_RELEASE);
        $this->assertSame('__phpc_mm_arena_malloc', Native::ARENA_MALLOC);
        $this->assertSame('__phpc_mm_arena_free', Native::ARENA_FREE);
        $this->assertSame('phpc_mm_arena_active', Native::G_ACTIVE);
    }

    public function testNativeSourceWiresArenaIntoRequestReset(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MemoryManager/Native.php');
        $this->assertStringContainsString('ARENA_RELEASE', $source);
        $this->assertStringContainsString('implementRequestReset', $source);
        $this->assertStringContainsString('G_ACTIVE', $source);
        $this->assertStringContainsString('malloc_usable_size', $source);
    }

    public function testMemoryGetUsageAotGrowsAndFrees(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36388_memory_usage_aot.php';
        $bin = sys_get_temp_dir().'/phpc_36388_mgu_'.getmypid();
        $cmd = 'php -d memory_limit=1536M '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString('floor_ok', $text);
        $this->assertStringContainsString('grew_ok', $text);
        $this->assertStringContainsString('freed_ok', $text);
    }
}
