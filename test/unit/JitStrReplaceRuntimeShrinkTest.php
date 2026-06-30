<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** str_replace()/str_ireplace() JIT routes through JitStringSearch, not libc strstr/strcasestr (#14017). */
final class JitStrReplaceRuntimeShrinkTest extends TestCase
{
    public function testJitStrReplaceUsesJitStringSearchNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStrReplace.php');
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString('strcasestr', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }
}
