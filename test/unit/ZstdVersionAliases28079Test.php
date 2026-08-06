<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\zstd\VmZstdNative;
use PHPCompiler\ext\zstd\ZstdExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** ZSTD_VERSION_* + Zstd\* aliases (#28079). */
final class ZstdVersionAliases28079Test extends TestCase
{
    public function testModuleRegistersVersionConstantsAndAliases(): void
    {
        $mod = (string) file_get_contents(__DIR__.'/../../ext/zstd/Module.php');
        $this->assertStringContainsString('ZSTD_VERSION_NUMBER', $mod);
        $this->assertStringContainsString('ZSTD_VERSION_TEXT', $mod);
        $this->assertStringContainsString('new ns_compress()', $mod);
        $this->assertStringContainsString('new ns_uncompress()', $mod);
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ext/zstd/ns_compress.php', $spine);
        $this->assertFileExists(__DIR__.'/../../test/repro/issue_28079_zstd_version_aliases.php');
    }

    public function testNativeVersionHelpers(): void
    {
        if (!ZstdExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('zstd not advertised');
        }
        $n = VmZstdNative::versionNumber();
        $this->assertGreaterThan(0, $n);
        $text = VmZstdNative::versionText();
        $this->assertNotSame('', $text);
        $this->assertNotSame('0.0.0', $text);
    }
}
