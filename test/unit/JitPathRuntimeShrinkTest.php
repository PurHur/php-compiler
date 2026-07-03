<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** dirname()/basename() JIT uses PathJitHelper PHP, not JitStringSearch scheme LLVM (#15286). */
final class JitPathRuntimeShrinkTest extends TestCase
{
    public function testJitPathUsesPathJitHelperNotJitStringSearch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPath.php');
        $this->assertStringContainsString('StringPath::invokeDirname', $source);
        $this->assertStringNotContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }
}
