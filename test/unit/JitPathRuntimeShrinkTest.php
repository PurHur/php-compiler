<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** dirname()/basename() JIT uses JitStringSearch for scheme probe, not libc strstr (#14079, #4146). */
final class JitPathRuntimeShrinkTest extends TestCase
{
    public function testJitPathUsesJitStringSearchNotLibcStrstr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPath.php');
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }
}
