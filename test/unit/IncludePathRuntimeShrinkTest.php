<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** IncludePathRuntime: embed via IncludePathJitHelper; thin AOT via isThinStandaloneAotMain (#9245, #13678, #20308). */
final class IncludePathRuntimeShrinkTest extends TestCase
{
    public function testIncludePathRuntimeUsesJitHelperNotStandaloneLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IncludePathRuntime.php');
        $this->assertStringContainsString('IncludePathJitHelper', $source);
        $this->assertStringContainsString('IncludePathResolveJitHelper', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('implementThinStandaloneStubs', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('IncludePathStandaloneLlvm', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/IncludePathStandaloneLlvm.php');
    }
}
