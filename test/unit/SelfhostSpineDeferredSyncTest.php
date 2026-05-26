<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * M2 spine native-link deferred drift guard (issue #2202).
 */
final class SelfhostSpineDeferredSyncTest extends TestCase
{
    public function testBootstrapSpineDeferredLibMatchesCoverageScript(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root.'/script/bootstrap-spine-deferred-lib.php';
        $deferred = bootstrap_spine_native_link_deferred();

        $coverage = (string) file_get_contents($root.'/script/check-selfhost-spine-coverage-sync.php');
        $this->assertStringContainsString('bootstrap-spine-deferred-lib.php', $coverage);
        $this->assertStringContainsString('bootstrap_spine_native_link_deferred()', $coverage);
        $this->assertSame($deferred, bootstrap_spine_native_link_deferred());
    }

    public function testSelfhostSpineDeferredSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-selfhost-spine-deferred-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('check-selfhost-spine-deferred-sync: OK', implode("\n", $out));
    }
}
