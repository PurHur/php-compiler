<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Whole-script MCJIT deferral diagnostics (#36222).
 */
final class JitVmLoweringPolicyTest extends TestCase
{
    public function testTypedReturnReportsDeferralReason(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function f(): int {
    return 1;
}
echo f();
PHP,
            'typed_return.php'
        );
        $this->assertNotNull($block);
        $reasons = Block::requiresVmLoweringReasons($block);
        $this->assertContains('typed non-void return (#2114)', $reasons);
        $this->assertStringContainsString(
            'typed non-void return (#2114)',
            JitVmLoweringPolicy::formatDeferralLine($reasons[0])
        );
    }

    public function testUntypedScriptHasNoDeferralReasons(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function greet() {
    return 'ok';
}
echo greet();
PHP,
            'untyped_return.php'
        );
        $this->assertNotNull($block);
        $this->assertSame([], Block::requiresVmLoweringReasons($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    public function testJitRequireEnabledReadsTruthyEnv(): void
    {
        $prev = getenv('PHP_COMPILER_JIT_REQUIRE');
        putenv('PHP_COMPILER_JIT_REQUIRE=1');
        unset($_ENV['PHP_COMPILER_JIT_REQUIRE'], $_SERVER['PHP_COMPILER_JIT_REQUIRE']);
        try {
            $this->assertTrue(JitVmLoweringPolicy::jitRequireEnabled());
            putenv('PHP_COMPILER_JIT_REQUIRE=yes');
            $this->assertTrue(JitVmLoweringPolicy::jitRequireEnabled());
            putenv('PHP_COMPILER_JIT_REQUIRE=0');
            $this->assertFalse(JitVmLoweringPolicy::jitRequireEnabled());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_JIT_REQUIRE');
            } else {
                putenv('PHP_COMPILER_JIT_REQUIRE='.$prev);
            }
            unset($_ENV['PHP_COMPILER_JIT_REQUIRE'], $_SERVER['PHP_COMPILER_JIT_REQUIRE']);
        }
    }
}
