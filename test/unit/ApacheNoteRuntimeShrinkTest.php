<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * apache_note NestedJIT via JitVmHelperLink (#6276, #26120).
 */
final class ApacheNoteRuntimeShrinkTest extends TestCase
{
    public function testApacheNoteUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/apache_note.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('/ext/standard/ApacheNoteJitHelper.php', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testApacheGetVersionRoutesThroughApacheNoteEmit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/apache_get_version.php');
        $this->assertStringContainsString('apache_note::emitUnavailableJit', $source);
        $this->assertStringContainsString('ApacheNoteJitHelper::class.\'::versionUnavailable\'', $source);
    }
}
