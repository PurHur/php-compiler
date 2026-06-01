<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for WeakMap offsetUnset + foreach JIT (#4084).
 *
 * @group llvm
 */
final class WeakMapOffsetUnsetJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — WeakMap offsetUnset JIT compile test needs LLVM (#4084)');
        }
    }

    public function testWeakMapOffsetUnsetAndForeachLowerWithoutLogicException(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {}
$m = new WeakMap();
$o = new Box();
$m->offsetSet($o, 1);
unset($m[$o]);
var_export(isset($m[$o]));
echo "\n";
foreach ($m as $k => $v) {
    echo "iter\n";
}
PHP
            ,
            'weakmap_offsetunset_jit_compile.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        // MCJIT link/execute still unstable for WeakMap (#3667/#98); compile lowering is the gate (#4084).
        $this->addToAssertionCount(1);
    }
}
