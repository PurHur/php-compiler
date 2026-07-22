<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GraphemeStrSplitRuntime: NestedJIT via JitVmHelperLink::ensureCompiled (#22147 / peer #22124).
 */
final class GraphemeStrSplitRuntimeShrinkTest extends TestCase
{
    public function testGraphemeStrSplitRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GraphemeStrSplitRuntime.php');
        $this->assertStringContainsString('GraphemeStrSplitJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }
}
