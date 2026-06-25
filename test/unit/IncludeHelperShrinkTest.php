<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** IncludeHelper must delegate self-host guards to IncludeJitHelper / VmInclude (#10063). */
final class IncludeHelperShrinkTest extends TestCase
{
    public function testIncludeHelperDelegatesToIncludeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/IncludeHelper.php');
        $this->assertStringContainsString('IncludeJitHelper::', $source);
        $this->assertStringNotContainsString('function shouldSkipSelfHostSpineCliInclude', $source);
        $this->assertStringNotContainsString('function shouldStubM3SidecarHostNonLiteralInclude', $source);
        $this->assertStringNotContainsString('function resolveLiteralPath', $source);
    }

    public function testIncludeJitHelperExistsWithResolveLiteralPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IncludeJitHelper.php');
        $this->assertStringContainsString('VmInclude', $source);
        $this->assertStringContainsString('resolveLiteralPath', $source);
    }
}
