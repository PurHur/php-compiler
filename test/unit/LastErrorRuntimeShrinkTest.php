<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** LastErrorRuntime must route through ErrorLastJitHelper PHP, not LLVM globals (#9454, #9607). */
final class LastErrorRuntimeShrinkTest extends TestCase
{
    public function testLastErrorRuntimeUsesErrorLastJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LastErrorRuntime.php');
        $this->assertStringContainsString('ErrorLastJitHelper', $source);
        $this->assertStringNotContainsString('phpc_last_error_active', $source);
        $this->assertStringNotContainsString('phpc_last_error_message', $source);
        $this->assertStringNotContainsString('G_ACTIVE', $source);
        $this->assertStringNotContainsString('LastErrorRuntimeLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/LastErrorRuntimeLlvm.php');
    }
}
