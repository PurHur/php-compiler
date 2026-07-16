<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPUnit\Framework\TestCase;

/**
 * php://temp stream_select cast must prefer VmPhpFdStream mkstemp over host tmpfile (#19691).
 */
final class PhpTempSelectCastRuntimeShrinkTest extends TestCase
{
    public function testCastFdForSelectDoesNotRequireHostTmpfileWhenFfiAvailable(): void
    {
        if (!VmPhpFdStream::available()) {
            $this->markTestSkipped('FFI/libc unavailable');
        }

        $handle = VmPhpMemoryStream::open('php://temp', 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame(3, VmPhpMemoryStream::write($handle, 'abc'));
        VmPhpMemoryStream::seek($handle, 0, SEEK_SET);

        $fd = VmPhpMemoryStream::castFdForSelect($handle);
        $this->assertNotNull($fd);
        $this->assertGreaterThanOrEqual(0, $fd);
        VmPhpFdStream::closeRawFd($fd);
        VmPhpMemoryStream::close($handle);
    }

    public function testCastFdPathIsPreferredInVmStreamSelectSource(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamSelect.php');
        $fdPos = strpos($source, 'castFdForSelect');
        $hostPos = strpos($source, 'castHostResourceForSelect');
        $this->assertNotFalse($fdPos);
        $this->assertNotFalse($hostPos);
        $this->assertLessThan($hostPos, $fdPos);
    }
}
