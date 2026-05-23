<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Self-host compile probe script (issue #816).
 */
final class BootstrapSelfhostCompileProbeTest extends TestCase
{
    public function testProbeScriptPrintsNextLowerOnFailure(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-selfhost-compile-probe.php';
        $this->assertFileExists($script);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1';
        $out = shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertStringContainsString('NEXT_LOWER:', $out);
    }
}
