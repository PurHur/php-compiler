<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #27856 — brotli streaming + BROTLI_* constants when advertised.
 */
final class BrotliStreaming27856Test extends TestCase
{
    public function testModuleRegistersStreamingAndConstants(): void
    {
        $mod = (string) file_get_contents(__DIR__.'/../../ext/brotli/Module.php');
        $this->assertStringContainsString('brotli_compress_init', $mod);
        $this->assertStringContainsString('brotli_compress_add', $mod);
        $this->assertStringContainsString('brotli_uncompress_init', $mod);
        $this->assertStringContainsString('brotli_uncompress_add', $mod);
        $this->assertStringContainsString('BROTLI_GENERIC', $mod);
        $this->assertStringContainsString('BROTLI_FINISH', $mod);
        $this->assertStringContainsString('VmBrotliContext::registerClasses', $mod);
    }

    public function testSpineIncludesStreamingSources(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VmBrotliContext.php', $spine);
        $this->assertStringContainsString('brotli_compress_init.php', $spine);
        $this->assertStringContainsString('brotli_uncompress_add.php', $spine);
    }

    public function testReproExists(): void
    {
        $this->assertFileExists(__DIR__.'/../../test/repro/issue_27856_brotli_streaming.php');
    }
}
