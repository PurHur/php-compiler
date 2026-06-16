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

    public function testTmpfileReadWriteViaVmFs(): void
    {
        $handle = VmFs::tmpfile();
        $this->assertNotFalse($handle);

        $this->assertSame(4, VmFs::fwrite($handle, 'data'));
        $this->assertTrue(VmFs::rewind($handle));
        $this->assertSame('data', VmFs::streamGetContents($handle));
        $this->assertTrue(VmFs::fclose($handle));
    }

    public function testNativeOpenReturnsVmStreamHandle(): void
    {
        $handle = VmTmpfileNative::open();
        $this->assertNotFalse($handle);
        $this->assertIsInt($handle);

        $this->assertSame(5, VmFs::fwrite($handle, 'hello'));
        $this->assertTrue(VmFs::rewind($handle));
        $this->assertSame('hello', VmFs::streamGetContents($handle));
        $this->assertTrue(VmFs::fclose($handle));
    }
}
