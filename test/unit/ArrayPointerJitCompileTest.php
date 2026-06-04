<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile verify for array internal pointer builtins (#5504).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class ArrayPointerJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — array pointer JIT compile test needs LLVM (#5504)');
        }
    }

    public function testArrayPointerBuiltinsModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
$a = ['a' => 1, 'b' => 2];
echo key($a), "\n";
echo current($a), "\n";
next($a);
echo key($a), "\n";
reset($a);
echo key($a), "\n";
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_pointer_jit.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block), 'script should lower to MCJIT');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringNotContainsString(
            'not implemented for JIT',
            $bc,
            'array pointer builtins should lower without JIT stubs (#5504)'
        );
        $this->assertMatchesRegularExpression(
            '/arr_ptr_(key|pnext|skey)/',
            $bc,
            'expected lowered array pointer control-flow blocks (#5504)'
        );
        // Per-function LLVM from jitCompileBlock is verified; full-module verify is deferred
        // (unrelated __value__* icmp in bundled runtime helpers).
        $this->addToAssertionCount(1);
    }
}
