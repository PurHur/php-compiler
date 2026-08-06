<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #27882 — zstd streaming + level constants. */
final class ZstdStreaming27882Test extends TestCase
{
    public function testModuleRegistersStreamingAndConstants(): void
    {
        $mod = (string) file_get_contents(__DIR__.'/../../ext/zstd/Module.php');
        $this->assertStringContainsString('zstd_compress_init', $mod);
        $this->assertStringContainsString('ZSTD_COMPRESS_LEVEL_DEFAULT', $mod);
        $this->assertStringContainsString('VmZstdContext::registerClasses', $mod);
    }

    public function testSpineIncludesStreamingSources(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VmZstdContext.php', $spine);
        $this->assertStringContainsString('zstd_compress_add.php', $spine);
    }
}
