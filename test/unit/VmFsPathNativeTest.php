<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsPathNative;
use PHPCompiler\ext\standard\VmFsPathPure;
use PHPUnit\Framework\TestCase;

/** VmFsPathNative routes rename/copy/link through VmFsPathPure (#5213, #12316). */
final class VmFsPathNativeTest extends TestCase
{
    public function testVmFsUsesPathNativeNotHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsPathNative::rename', $source);
        $this->assertStringContainsString('VmFsPathNative::copy', $source);
        $this->assertStringContainsString('VmFsPathNative::link', $source);
        $this->assertStringContainsString('VmFsPathNative::readlink', $source);
        $this->assertStringContainsString('VmFsPathNative::symlink', $source);
        $this->assertStringContainsString('VmFsTouchNative::touch', $source);
        $this->assertDoesNotMatchRegularExpression('/@rename\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@copy\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@link\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@readlink\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@symlink\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@touch\\s*\\(/', $source);
    }

    public function testNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsPathNative.php');
        $this->assertStringContainsString('VmFsPathPure::rename', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testRenameCopyLinkRoundTrip(): void
    {
        if (!VmFsPathPure::available()) {
            $this->markTestSkipped('host rename unavailable');
        }

        $dir = sys_get_temp_dir();
        $src = tempnam($dir, 'phpc_path_src_');
        $this->assertNotFalse($src);
        file_put_contents($src, 'payload');

        $copyDst = $dir.'/phpc_path_copy_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::copy($src, $copyDst));
        $this->assertSame('payload', file_get_contents($copyDst));

        $renamed = $dir.'/phpc_path_renamed_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::rename($copyDst, $renamed));
        $this->assertFileDoesNotExist($copyDst);
        $this->assertSame('payload', file_get_contents($renamed));

        $hard = $dir.'/phpc_path_hard_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::link($src, $hard));
        $this->assertSame('payload', file_get_contents($hard));

        $sym = $dir.'/phpc_path_sym_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::symlink($src, $sym));
        $this->assertSame($src, VmFsPathNative::readlink($sym));

        @unlink($src);
        @unlink($renamed);
        @unlink($hard);
        @unlink($sym);
    }

    public function testNullBytePathsRejected(): void
    {
        $this->assertFalse(VmFsPathNative::rename("a\0b", 'c'));
        $this->assertFalse(VmFsPathNative::copy("a\0b", 'c'));
        $this->assertFalse(VmFsPathNative::link("a\0b", 'c'));
        $this->assertFalse(VmFsPathNative::readlink("a\0b"));
        $this->assertFalse(VmFsPathNative::symlink("a\0b", 'c'));
        $this->assertFalse(VmFsPathNative::symlink('a', "b\0c"));
    }
}
