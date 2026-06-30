<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** substr_count() JIT routes through JitStringSearch, not libc strstr (#14069). */
final class JitSubstrCountRuntimeShrinkTest extends TestCase
{
    public function testJitSubstrCountUsesJitStringSearchNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSubstrCount.php');
        $this->assertStringContainsString('JitStringSearch::ensureLinked', $source);
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
        $this->assertStringNotContainsString('strcasestr', $source);
    }
}
