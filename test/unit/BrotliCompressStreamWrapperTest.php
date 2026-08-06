<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\brotli\VmBrotliNative;
use PHPCompiler\ext\brotli\VmBrotliStream;
use PHPCompiler\ext\standard\VmStreamWrapperRegistry;
use PHPUnit\Framework\TestCase;

/** compress.brotli:// registration + buffered I/O (#28115). */
final class BrotliCompressStreamWrapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        VmStreamWrapperRegistry::resetForTests();
        VmBrotliStream::resetForTests();
    }

    protected function tearDown(): void
    {
        VmBrotliStream::resetForTests();
        VmStreamWrapperRegistry::resetForTests();
        parent::tearDown();
    }

    public function testRegisterExtensionBuiltinListsCompressBrotli(): void
    {
        $this->assertNotContains('compress.brotli', VmStreamWrapperRegistry::getWrappers());
        $this->assertTrue(VmStreamWrapperRegistry::registerExtensionBuiltin('compress.brotli'));
        $this->assertContains('compress.brotli', VmStreamWrapperRegistry::getWrappers());
        $this->assertFalse(VmStreamWrapperRegistry::registerExtensionBuiltin('compress.brotli'));
    }

    public function testUnregisterExtensionBuiltinRemovesScheme(): void
    {
        $this->assertTrue(VmStreamWrapperRegistry::registerExtensionBuiltin('compress.brotli'));
        $this->assertTrue(VmStreamWrapperRegistry::unregister('compress.brotli'));
        $this->assertNotContains('compress.brotli', VmStreamWrapperRegistry::getWrappers());
        $this->assertTrue(VmStreamWrapperRegistry::restore('compress.brotli'));
        $this->assertContains('compress.brotli', VmStreamWrapperRegistry::getWrappers());
    }

    public function testModuleRegistersCompressBrotliWrapper(): void
    {
        $mod = (string) file_get_contents(__DIR__.'/../../ext/brotli/Module.php');
        $this->assertStringContainsString('registerExtensionBuiltin', $mod);
        $this->assertStringContainsString('VmBrotliStream::PROTOCOL', $mod);
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VmBrotliStream.php', $spine);
        $this->assertFileExists(__DIR__.'/../../test/repro/issue_28115_compress_brotli_stream.php');
    }

    public function testBufferedRoundTripMatchesBrotliCompress(): void
    {
        if (!CompilerVersion::supportsBrotli() || !VmBrotliNative::available()) {
            $this->markTestSkipped('brotli not advertised / libbrotli unavailable');
        }
        $plain = 'unit-brotli-stream-'.bin2hex(random_bytes(4));
        $path = sys_get_temp_dir().'/phpc_brotli_unit_'.getmypid().'.br';
        @unlink($path);
        $uri = 'compress.brotli://'.$path;
        $w = VmBrotliStream::open($uri, 'wb');
        $this->assertNotFalse($w);
        $this->assertSame(\strlen($plain), VmBrotliStream::write((int) $w, $plain));
        $this->assertTrue(VmBrotliStream::close((int) $w));
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $this->assertSame(VmBrotliNative::compress($plain), $raw);
        $r = VmBrotliStream::open($uri, 'rb');
        $this->assertNotFalse($r);
        $got = VmBrotliStream::streamGetContents((int) $r);
        $this->assertSame($plain, $got);
        $this->assertTrue(VmBrotliStream::close((int) $r));
        @unlink($path);
    }
}
