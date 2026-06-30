<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** stristr()/strstr() JIT routes through JitStringSearch, not libc strstr/strcasestr (#14010, #14017). */
final class JitStrstrRuntimeShrinkTest extends TestCase
{
    public function testJitStrstrPathsUseJitStringSearchNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrstr.php');
        $this->assertStringContainsString('findCaseInsensitive', $source);
        $this->assertStringContainsString('findCaseSensitive', $source);
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString('strcasestr', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }
}
