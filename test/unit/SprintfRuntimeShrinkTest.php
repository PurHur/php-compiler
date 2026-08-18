<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop always-on libc sprintf(3) from Builtin\Output (#32110).
 */
final class SprintfRuntimeShrinkTest extends TestCase
{
    public function testOutputBuiltinNoLongerDeclaresAlwaysOnSprintf(): void
    {
        $output = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Output.php');
        $this->assertStringNotContainsString("addFunction('sprintf'", $output);
        $this->assertStringContainsString('#32110', $output);
    }

    public function testHashTableResourceKeyUsesSnprintfEnsure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('LibcExtern::ensureSnprintf', $source);
        $this->assertStringContainsString("lookupFunction('snprintf')", $source);
        $this->assertStringNotContainsString("lookupFunction('sprintf')", $source);
        $this->assertStringContainsString('#32110', $source);
    }

    public function testFilterScalarCoercionUsesSnprintfEnsure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('LibcExtern::ensureSnprintf', $source);
        $this->assertStringContainsString("lookupFunction('snprintf')", $source);
        $this->assertStringNotContainsString("lookupFunction('sprintf')", $source);
        $this->assertStringContainsString('#32110', $source);
    }
}

