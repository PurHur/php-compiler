<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmZlib;
use PHPCompiler\ext\standard\VmZlibNative;
use PHPUnit\Framework\TestCase;

/** Issue #6356: VM zlib must not delegate to host \\gzcompress(). */
final class VmZlibTest extends TestCase
{
    public function testVmZlibDoesNotReferenceHostGzcompress(): void
    {
        $source = file_get_contents(__DIR__.'/../../ext/standard/VmZlib.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('function_exists(\'gzcompress\')', $source);
        $this->assertStringNotContainsString('\\gzcompress(', $source);
        $this->assertStringNotContainsString('hostGz', $source);
    }

    public function testVmZlibRoundTripWhenNativeAvailable(): void
    {
        if (!VmZlibNative::available()) {
            $this->markTestSkipped('libz FFI unavailable on this host');
        }

        $plain = 'bootstrap zlib';
        $compressed = VmZlib::gzcompress($plain);
        $this->assertIsString($compressed);
        $this->assertSame($plain, VmZlib::gzuncompress($compressed));
    }
}
