<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmFnmatch routes exclusively through VmFnmatchPure — no libc fnmatch FFI (#12075). */
final class VmFnmatchRuntimeShrinkTest extends TestCase
{
    public function testVmFnmatchHasNoLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFnmatch.php');
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('fnmatch(const char', $source);
        $this->assertStringContainsString('VmFnmatchPure::match', $source);
        $this->assertLessThan(40, substr_count($source, "\n"));
    }
}
