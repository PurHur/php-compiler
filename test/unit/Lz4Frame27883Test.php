<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #27883 — lz4 frame API + LZ4_* constants. */
final class Lz4Frame27883Test extends TestCase
{
    public function testModuleRegistersFrameAndConstants(): void
    {
        $mod = (string) file_get_contents(__DIR__.'/../../ext/lz4/Module.php');
        $this->assertStringContainsString('lz4_compress_frame', $mod);
        $this->assertStringContainsString('LZ4_CLEVEL_MIN', $mod);
        $this->assertStringContainsString('LZ4_VERSION_TEXT', $mod);
    }

    public function testSpineIncludesFrameSources(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('lz4_compress_frame.php', $spine);
        $this->assertStringContainsString('lz4_uncompress_frame.php', $spine);
    }
}
