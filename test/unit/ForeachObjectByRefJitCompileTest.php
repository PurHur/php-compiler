<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * foreach by-reference over object properties — MCJIT lowering (#5034, #3661).
 *
 * @group llvm
 * @group jit
 */
final class ForeachObjectByRefJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — foreach object by-ref JIT needs LLVM (#5034)');
        }
    }

    public function testUserClassForeachByRefCompilesWithoutIteratorHelperObjectRejection(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public int $a = 1;
}
$o = new C();
foreach ($o as &$v) {
    $v = 2;
}
echo $o->a, "\n";
PHP
            ,
            'foreach_object_by_ref_user.php'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $ir = $runtime->loadJitContext()->module->printToString();
        $this->assertStringContainsString('foreach_objprop_', $ir);
        $this->addToAssertionCount(1);
    }

    public function testStdClassForeachByRefLowersObjectPropertyWalk(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
$o = new stdClass();
$o->a = 1;
foreach ($o as &$v) {
    $v = 2;
}
echo $o->a, "\n";
PHP
            ,
            'foreach_object_by_ref_std.php'
        );
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $ir = $runtime->loadJitContext()->module->printToString();
        $this->assertStringContainsString('foreach_objprop_', $ir);
        $this->addToAssertionCount(1);
    }
}
