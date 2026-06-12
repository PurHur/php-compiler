<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamMeta;
use PHPCompiler\ext\standard\VmStreamSync;
use PHPUnit\Framework\TestCase;

/** VmStreamSync fsync probe without host stream_get_meta_data() (#8118, #7339 phase 2). */
final class VmStreamSyncRuntimeShrinkTest extends TestCase
{
    public function testVmStreamSyncDoesNotDelegateToHostStreamGetMetaData(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSync.php');
        $this->assertStringContainsString('VmStreamMeta::supportsSync', $source);
        $this->assertStringNotContainsString('\\stream_get_meta_data(', $source);
    }

    public function testSupportsSyncRejectsPhpMemoryAndSocketTransports(): void
    {
        $this->assertFalse(VmStreamMeta::supportsSync('php://memory'));
        $this->assertFalse(VmStreamMeta::supportsSync('php://temp'));
        $this->assertFalse(VmStreamMeta::supportsSync('tcp://127.0.0.1:80'));
        $this->assertTrue(VmStreamMeta::supportsSync('/tmp/example.txt'));
    }

    public function testIsSupportedOnMemoryHandle(): void
    {
        $handle = VmFs::fopen('php://memory', 'w');
        $this->assertNotFalse($handle);
        $this->assertFalse(VmStreamSync::isSupported($handle));
        VmFs::fclose($handle);
    }

    public function testIsSupportedOnPlainFileHandle(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'phpc_stream_sync_');
        $this->assertIsString($path);
        $handle = VmFs::fopen($path, 'w');
        $this->assertNotFalse($handle);
        $this->assertTrue(VmStreamSync::isSupported($handle));
        VmFs::fclose($handle);
        @\unlink($path);
    }
}
