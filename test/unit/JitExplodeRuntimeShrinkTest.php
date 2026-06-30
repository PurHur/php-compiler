<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** explode() JIT routes through JitStringSearch, not libc strstr (#14019). */
final class JitExplodeRuntimeShrinkTest extends TestCase
{
    public function testJitExplodeUsesJitStringSearchNotLibcStrstr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitExplode.php');
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }
}
