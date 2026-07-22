<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Global introspection name JIT routes through GlobalIntrospectionNameJitHelper PHP (#12176, #22070). */
final class GlobalIntrospectionNameRuntimeShrinkTest extends TestCase
{
    public function testGlobalIntrospectionNameRuntimeUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GlobalIntrospectionNameRuntime.php');
        $this->assertStringContainsString('GlobalIntrospectionNameJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
    }
}
