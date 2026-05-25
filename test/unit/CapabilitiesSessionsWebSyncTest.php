<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Capability docs drift guard for 005-SessionsWeb + session AOT columns (issue #1947, #1976).
 */
final class CapabilitiesSessionsWebSyncTest extends TestCase
{
    public function testCapabilitiesSessionsWebSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-capabilities-sessionsweb-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
