<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFnmatch;
use PHPCompiler\ext\standard\VmFnmatchPure;
use PHPUnit\Framework\TestCase;

/** Issue #7756 / #8016 / #12075: VM fnmatch() via VmFnmatchPure, no libc FFI. */
final class VmFnmatchTest extends TestCase
{
    public function testVmFnmatchDoesNotDelegateToHostFnmatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFnmatch.php');
        $this->assertStringNotContainsString('\\fnmatch(', $source);
        $this->assertStringNotContainsString('hostMatch', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('libcMatch', $source);
        $this->assertStringContainsString('VmFnmatchPure::match', $source);
    }

    public function testFnmatchBuiltinUsesVmFnmatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/fnmatch.php');
        $this->assertStringContainsString('VmFnmatch::match', $source);
        $this->assertStringNotContainsString('\\fnmatch(', $source);
    }

    public function testPureMatcherMatchesComplianceCases(): void
    {
        $this->assertTrue(VmFnmatchPure::match('*.txt', 'readme.txt'));
        $this->assertFalse(VmFnmatchPure::match('*.txt', 'readme.php'));
        $this->assertTrue(VmFnmatchPure::match('file?.txt', 'file1.txt'));
        $this->assertFalse(VmFnmatchPure::match('file?.txt', 'file12.txt'));
        $this->assertTrue(VmFnmatchPure::match('foo*', 'foo/bar'));
        $this->assertFalse(VmFnmatchPure::match('foo*', 'foo/bar', VmFnmatch::FNM_PATHNAME));
        $this->assertTrue(VmFnmatchPure::match('FILE?.TXT', 'file1.txt', VmFnmatch::FNM_CASEFOLD));
    }

    public function testPureMatcherFlagParity(): void
    {
        $this->assertFalse(VmFnmatchPure::match('*.TXT', 'a.txt'));
        $this->assertTrue(VmFnmatchPure::match('*.TXT', 'a.txt', VmFnmatch::FNM_CASEFOLD));
        $this->assertFalse(VmFnmatchPure::match('*', '.hidden', VmFnmatch::FNM_PERIOD));
        $this->assertTrue(VmFnmatchPure::match('.*', '.hidden', VmFnmatch::FNM_PERIOD));
        $this->assertFalse(VmFnmatchPure::match('*', 'a/b', VmFnmatch::FNM_PATHNAME));
        $this->assertTrue(VmFnmatchPure::match('*/b', 'a/b', VmFnmatch::FNM_PATHNAME));
        $this->assertTrue(VmFnmatchPure::match('a\\*b', 'a*b'));
        $this->assertFalse(VmFnmatchPure::match('a\\*b', 'a*b', VmFnmatch::FNM_NOESCAPE));
    }

    public function testVmFnmatchAvailableWithoutLibcFfi(): void
    {
        $this->assertTrue(VmFnmatchPure::available());
        $this->assertTrue(VmFnmatch::available());
    }

    public function testMatchBasicPatterns(): void
    {
        $this->assertTrue(VmFnmatch::match('*.txt', 'readme.txt'));
        $this->assertFalse(VmFnmatch::match('*.txt', 'readme.php'));
        $this->assertTrue(VmFnmatch::match('file?.txt', 'file1.txt'));
        $this->assertFalse(VmFnmatch::match('foo*', 'foo/bar', VmFnmatch::FNM_PATHNAME));
        $this->assertTrue(VmFnmatch::match('FILE?.TXT', 'file1.txt', VmFnmatch::FNM_CASEFOLD));
    }
}
