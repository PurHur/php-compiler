<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** stristr() JIT routes through JitStringSearch CI, not libc strcasestr (#14010). */
final class JitStrstrRuntimeShrinkTest extends TestCase
{
    public function testJitStrstrCiPathUsesJitStringSearchNotStrcasestr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrstr.php');
        $this->assertStringContainsString('findCaseInsensitive', $source);
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString('strcasestr', $source);
    }
}
