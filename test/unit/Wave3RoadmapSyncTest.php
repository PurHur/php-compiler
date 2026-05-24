<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Wave 3 roadmap drift guard (issue #1802).
 */
final class Wave3RoadmapSyncTest extends TestCase
{
    public function testWave3RoadmapSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-wave3-roadmap-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
