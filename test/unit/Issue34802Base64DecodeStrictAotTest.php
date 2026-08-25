<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #34802 — AOT base64_decode($s, true) string|false boxes; roundtrip + getimagesize.
 *
 * @group aot
 */
final class Issue34802Base64DecodeStrictAotTest extends TestCase
{
    public function testCallBoxesStringOrFalseForTwoArgForm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/base64_decode.php');
        $this->assertStringContainsString('boxStringOrFalse', $source);
        $this->assertStringContainsString('compileTimeBool', $source);
        $this->assertStringContainsString('#34802', $source);
        $this->assertStringContainsString('__value__writeString', $source);
    }

    public function testAotStrictDecodeRoundtripAndGetimagesize(): void
    {
        if (!\extension_loaded('FFI')) {
            $this->markTestSkipped('FFI required for AOT compile');
        }
        $repro = __DIR__.'/../repro/issue_34802_base64_encode_binary_aot.php';
        $bin = sys_get_temp_dir().'/phpc-34802-'.getmypid().'.bin';
        $compile = 'php bin/compile.php -o '.escapeshellarg($bin).' '.escapeshellarg($repro).' 2>&1';
        $env = 'PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg(sys_get_temp_dir().'/phpc-34802-cache-'.getmypid());
        exec('cd '.escapeshellarg(dirname(__DIR__, 2)).' && env '.$env.' '.$compile, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString('enc_literal=iVBORw0KGgo=', $text);
        $this->assertStringContainsString('enc_roundtrip=iVBORw0KGgo=', $text);
        $this->assertStringContainsString('gis=1x1:image/png', $text);
    }
}
