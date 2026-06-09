<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFnmatch;
use PHPUnit\Framework\TestCase;

/** Issue #7756: VM fnmatch() must not require host \\fnmatch() when libc FFI is available. */
final class VmFnmatchTest extends TestCase
{
    public function testVmFnmatchDoesNotRequireHostFnmatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFnmatch.php');
        $this->assertStringNotContainsString("throw new \\LogicException('fnmatch() requires host fnmatch()", $source);
        $this->assertStringContainsString('libcMatch', $source);
        $this->assertStringContainsString('fnmatch(3)', $source);
    }

    public function testFnmatchBuiltinUsesVmFnmatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/fnmatch.php');
        $this->assertStringContainsString('VmFnmatch::match', $source);
        $this->assertStringNotContainsString('\\fnmatch(', $source);
    }

    public function testMatchBasicPatternsWhenLibcAvailable(): void
    {
        if (!VmFnmatch::available()) {
            $this->markTestSkipped('libc fnmatch FFI unavailable on this host');
        }

        $this->assertTrue(VmFnmatch::match('*.txt', 'readme.txt'));
        $this->assertFalse(VmFnmatch::match('*.txt', 'readme.php'));
        $this->assertTrue(VmFnmatch::match('file?.txt', 'file1.txt'));
        $this->assertFalse(VmFnmatch::match('foo*', 'foo/bar', VmFnmatch::FNM_PATHNAME));
        $this->assertTrue(VmFnmatch::match('FILE?.TXT', 'file1.txt', VmFnmatch::FNM_CASEFOLD));
    }
}
