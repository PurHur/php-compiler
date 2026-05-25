<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * M2 spine count drift guard (issues #1834, #1872).
 */
final class SelfhostSpineCountSyncTest extends TestCase
{
    public function testBootstrapSpineCountMatchesBundle(): void
    {
        $root = dirname(__DIR__, 2);
        $spineMain = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
        $this->assertFileExists($spineMain);
        $expectedSpine = substr_count((string) file_get_contents($spineMain), 'require_once __DIR__');

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-spine-count.php').' --json 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $counts = json_decode(implode("\n", $out), true);
        $this->assertIsArray($counts);
        $this->assertSame($expectedSpine, $counts['spine'] ?? null);
        $this->assertGreaterThan(0, $counts['inventory'] ?? 0);
    }

    public function testSelfhostSpineCountSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-selfhost-spine-count-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
