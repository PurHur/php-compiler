<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM lowering for #[\NoDiscard] discarded-call warnings (#5663).
 *
 * @group llvm
 */
final class NoDiscardJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        putenv('PHP_COMPILER_PROFILE=8.4');
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — NoDiscard JIT compile test needs LLVM (#5663)');
        }
    }

    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
    }

    public function testDiscardedCallLowersCompilerTriggerError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\NoDiscard]
function must_use(): int {
    return 1;
}
must_use();
PHP;
        $block = $runtime->parseAndCompile($code, 'nodiscard_jit_probe.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $ir = $context->module->printToString();
        $this->assertStringContainsString('__compiler_trigger_error', $ir);
        $this->assertStringContainsString(
            'should either be used or intentionally ignored by casting it as (void)',
            $ir
        );
        $this->assertArrayHasKey('must_use', $context->noDiscardCalleeMessages);
    }
}
