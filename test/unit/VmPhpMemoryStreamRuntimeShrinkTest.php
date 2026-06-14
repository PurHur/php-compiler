<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPUnit\Framework\TestCase;

/** VM php://memory/php://temp must not delegate to host @fopen (#4969, php-in-php). */
final class VmPhpMemoryStreamRuntimeShrinkTest extends TestCase
{
    public function testVmFsFopenDoesNotUseHostFopenForMemoryOrTemp(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmPhpMemoryStream::isSupportedUri', $source);
        $this->assertStringContainsString('VmPhpMemoryStream::open', $source);
        $this->assertStringNotContainsString("@fopen('php://memory'", $source);
        $this->assertStringNotContainsString("@fopen('php://temp'", $source);
    }

    public function testMemoryStreamRoundTripWithoutHostStreams(): void
    {
        $handle = VmPhpMemoryStream::open('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame(2, VmPhpMemoryStream::write($handle, 'hi'));
        $this->assertSame(0, VmPhpMemoryStream::seek($handle, 0, SEEK_SET));
        $this->assertSame('hi', VmPhpMemoryStream::streamGetContents($handle));
        VmPhpMemoryStream::close($handle);
    }

    public function testVmFsMemoryRoundTrip(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame(2, VmFs::fwrite($handle, 'hi'));
        $this->assertTrue(VmFs::rewind($handle));
        $this->assertSame('hi', VmFs::streamGetContents($handle));
        VmFs::fclose($handle);
    }

    public function testFseekSeekEndOnMemoryStream(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        VmFs::fwrite($handle, 'abc');
        $this->assertSame(0, VmFs::fseek($handle, -1, SEEK_END));
        $this->assertSame(2, VmFs::ftell($handle));
        $this->assertSame('c', VmFs::fgetc($handle));
        VmFs::fclose($handle);
    }
}
