<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** dirname()/basename() JIT emits path LLVM directly (#26905), not NestedJIT PathJitHelper. */
final class JitPathRuntimeShrinkTest extends TestCase
{
    public function testJitPathUsesInlineLlvmNotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPath.php');
        $this->assertStringContainsString('public static function dirname', $source);
        $this->assertStringContainsString('public static function basename', $source);
        $this->assertStringContainsString('trimTrailingSeparators', $source);
        $this->assertStringNotContainsString('StringPath::invokeDirname', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }
}
