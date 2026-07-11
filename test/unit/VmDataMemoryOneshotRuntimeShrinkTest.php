<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDataStream;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** data:// and php://memory one-shot helpers without host stream delegation (#10263, #10264). */
final class VmDataMemoryOneshotRuntimeShrinkTest extends TestCase
{
    public function testVmFsFopenRoutesDataUriThroughVmDataStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmDataStream::isSupportedUri', $source);
        $this->assertStringContainsString('VmDataStream::open', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/VmDataStream.php');
    }

    public function testVmFsFileGetContentsRoutesMemoryThroughOpen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('readPathContentsViaOpen', $source);
        $this->assertStringContainsString('filePutContentsViaOpen', $source);
    }

    public function testDataStreamOpenRoundTrip(): void
    {
        $handle = VmDataStream::open('data://text/plain,hello', 'rb');
        $this->assertNotFalse($handle);
        $this->assertSame('hello', VmFs::streamGetContents($handle));
        VmFs::fclose($handle);
    }

    public function testMemoryOneshotHelpers(): void
    {
        $written = VmFs::filePutContents('php://memory', 'hi');
        $this->assertSame(2, $written);
        $this->assertSame('', VmFs::fileGetContents('php://memory'));
    }

    public function testCopyDataToMemory(): void
    {
        $this->assertTrue(VmFs::copy('data://text/plain,payload', 'php://memory'));
    }
}
