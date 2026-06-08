<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmTmpfileNative;
use PHPUnit\Framework\TestCase;

/** @covers issue #4929 */
final class VmTmpfileNativeTest extends TestCase
{
    public function testVmFsTmpfileDoesNotCallHostTmpfile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringNotContainsString('\\tmpfile()', $source);
    }

    public function testLibcTmpfileReadWriteViaVmFs(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI required for VmTmpfileNative');
        }

        $handle = VmFs::tmpfile();
        $this->assertNotFalse($handle);

        $this->assertSame(4, VmFs::fwrite($handle, 'data'));
        $this->assertTrue(VmFs::rewind($handle));
        $this->assertSame('data', VmFs::streamGetContents($handle));
        $this->assertTrue(VmFs::fclose($handle));
    }

    public function testNativeOpenReturnsStreamResource(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI required for VmTmpfileNative');
        }

        $fp = VmTmpfileNative::open();
        $this->assertNotFalse($fp);
        $this->assertTrue(\is_resource($fp));

        $this->assertSame(5, @fwrite($fp, 'hello'));
        $this->assertSame(0, @fseek($fp, 0, \SEEK_SET));
        $this->assertSame('hello', @stream_get_contents($fp));
        $this->assertTrue(@fclose($fp));
    }
}
