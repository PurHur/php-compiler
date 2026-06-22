<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringOffsetRuntime routes semantics through StringOffsetJitHelper PHP (#10245). */
final class StringOffsetRuntimeShrinkTest extends TestCase
{
    public function testStringOffsetRuntimeUsesCompiledJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringOffsetRuntime.php');
        $this->assertStringContainsString('StringOffsetJitHelper', $source);
        $this->assertStringContainsString('normalizeByteIndex', $source);
        $this->assertStringContainsString('ensureJitHelperCompiled', $source);
    }

    public function testStringOffsetHelperDelegatesToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/StringOffsetHelper.php');
        $this->assertStringContainsString('StringOffsetRuntime', $source);
        $this->assertStringNotContainsString('str_offset_neg', $source);
        $this->assertLessThanOrEqual(70, substr_count($source, "\n") + 1);
    }

    public function testStringOffsetJitHelperDefinesNormalize(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/StringOffsetJitHelper.php');
        $this->assertStringContainsString('normalizeByteIndex', $source);
        $this->assertStringContainsString('INCDEC_ERROR', $source);
    }
}
