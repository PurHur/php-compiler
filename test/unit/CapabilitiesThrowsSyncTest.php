<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Capability docs drift guard for throw / try / catch + 007-ThrowsWeb (issue #2144).
 */
final class CapabilitiesThrowsSyncTest extends TestCase
{
    public function testCapabilitiesThrowsSyncScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-capabilities-throws-sync.php';
        $this->assertFileExists($script);
    }

    public function testCapabilitiesThrowsSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-capabilities-throws-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
