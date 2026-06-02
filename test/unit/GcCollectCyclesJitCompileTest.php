<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for gc_collect_cycles() JIT lowering (#3160).
 *
 * @group llvm
 */
final class GcCollectCyclesJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — gc_collect_cycles JIT compile test needs LLVM');
        }
    }

    public function testGcCollectCyclesModuleVerifies(): void
    {
        $code = <<<'PHP'
<?php
class Node { public $next; }
$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);
echo gc_collect_cycles();
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'gc_collect_cycles_jit_compile.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);

        $module = $context->module;
        $this->assertNotNull($module->getNamedFunction('__compiler_gc_collect_cycles'));
        $this->assertNotNull($module->getNamedFunction('phpc_gc_register'));
        $this->addToAssertionCount(1);
    }
}
