<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\iconv\IconvJitHelper;
use PHPCompiler\ext\iconv\VmIconv;
use PHPUnit\Framework\TestCase;

/**
 * iconv() JIT routes through IconvJitHelper PHP (#9345 / #25570).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer #25544).
 */
final class IconvRuntimeShrinkTest extends TestCase
{
    public function testIconvRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IconvRuntime.php');
        $this->assertStringContainsString('IconvJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('ENC_UTF8', $source);
        $this->assertStringNotContainsString('UTF8_ALIASES', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(170, $lineCount);
        $this->assertGreaterThan(10, 182 - $lineCount);
    }

    public function testJitIconvUsesRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/iconv/JitIconv.php');
        $this->assertStringContainsString('IconvRuntimeLink', $source);
        $this->assertStringContainsString('__compiler_iconv', $source);
    }

    public function testIconvJitHelperMatchesVmIconv(): void
    {
        $bytes = "\xE9";
        $expected = VmIconv::iconv('ISO-8859-1', 'UTF-8', $bytes);
        $this->assertIsString($expected);
        $this->assertSame($expected, IconvJitHelper::convert('ISO-8859-1', 'UTF-8', $bytes));

        $this->assertNull(IconvJitHelper::convert('KOI8-R', 'UTF-8', 'x'));
        $this->assertNull(IconvJitHelper::convert('UTF-8', 'INVALID//IGNORE', 'hello'));
    }
}
