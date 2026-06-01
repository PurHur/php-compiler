<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile + MCJIT run for class_alias() lowering (#3178).
 *
 * @group llvm
 */
final class ClassAliasJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — class_alias JIT compile test needs LLVM');
        }
    }

    public function testClassAliasJitCompileAndRun(): void
    {
        $code = <<<'PHP'
<?php
class R {}
class_alias(R::class, 'A');
echo class_exists('A') ? 1 : 0;
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'class_alias_jit_compile.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame('1', $out);
    }
}
