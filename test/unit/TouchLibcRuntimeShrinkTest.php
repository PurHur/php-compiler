<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * touch() AOT utime body routes now via StringTime, not libc time(2) (#30472).
 */
final class TouchLibcRuntimeShrinkTest extends TestCase
{
    public function testTouchLibcRuntimeUsesStringTimeNotLibcTime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TouchLibcRuntime.php');
        $this->assertStringContainsString('StringTime::ensureLinked', $source);
        $this->assertStringContainsString('StringTime::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('time')", $source);
        $this->assertStringContainsString("lookupFunction('utime')", $source);
        $this->assertStringContainsString('#28995', $source);
        $this->assertStringContainsString('#30472', $source);
    }

    public function testLibcExternCommentDocumentsTouchStringTimeRoute(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('#30472', $source);
        $this->assertStringNotContainsString('TouchLibcRuntime declare', $source);
    }
}
