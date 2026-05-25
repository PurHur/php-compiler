<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Capability docs drift guard for 006-FileUploadWeb + multipart AOT columns (issue #2019).
 */
final class CapabilitiesFileUploadWebSyncTest extends TestCase
{
    public function testCapabilitiesFileUploadWebSyncPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-capabilities-fileuploadweb-sync.php').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
