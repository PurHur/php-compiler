<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * JitVmHelperLink: NestedJIT env clear via Context — no UserScriptAotDeferNestedJit (#15407, #20246).
 */
final class JitVmHelperLinkRuntimeShrinkTest extends TestCase
{
    public function testJitVmHelperLinkUsesContextGateNotDeferClass(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitVmHelperLink.php');
        $this->assertStringContainsString('shouldClearUserScriptEnvForNestedHelperCompile', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testContextExposesNestedHelperEnvClearGate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('shouldClearUserScriptEnvForNestedHelperCompile', $source);
        $this->assertStringContainsString('isUserScriptAot()', $source);
    }

    public function testUserScriptAotDeferNestedJitClassDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/UserScriptAotDeferNestedJit.php');
    }
}
