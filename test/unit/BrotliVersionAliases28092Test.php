<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\brotli\VmBrotliNative;
use PHPUnit\Framework\TestCase;

/** BROTLI_VERSION_* + Brotli\* aliases (#28092). */
final class BrotliVersionAliases28092Test extends TestCase
{
    public function testModuleRegistersVersionConstantsAndAliases(): void
    {
        $mod = (string) file_get_contents(__DIR__.'/../../ext/brotli/Module.php');
        $this->assertStringContainsString('BROTLI_VERSION_NUMBER', $mod);
        $this->assertStringContainsString('BROTLI_VERSION_TEXT', $mod);
        $this->assertStringContainsString('BROTLI_DICTIONARY_SUPPORT', $mod);
        $this->assertStringContainsString('new ns_compress()', $mod);
        $this->assertStringContainsString('new ns_uncompress()', $mod);
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ns_compress.php', $spine);
        $this->assertFileExists(__DIR__.'/../../test/repro/issue_28092_brotli_version_aliases.php');
    }

    public function testNativeVersionHelpers(): void
    {
        if (!CompilerVersion::supportsBrotli() || !VmBrotliNative::available()) {
            $this->markTestSkipped('brotli not advertised / libbrotli unavailable');
        }
        $n = VmBrotliNative::versionNumber();
        $this->assertGreaterThan(0, $n);
        $text = VmBrotliNative::versionText();
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $text);
        $this->assertFalse(VmBrotliNative::dictionarySupport());
    }
}
