<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: DOMNode::cloneNode(...)?->tagName must match Zend (#34024).
 *
 * @see php-src ext/dom/node.c dom_node_clone_node
 * @see \PHPCompiler\JIT\Call\DomNodeCloneNode
 *
 * @group llvm
 * @group aot
 */
final class Issue34024DomNullsafeBoxedAotTest extends TestCase
{
    public function testCloneNodeReturnIsBoxedAndForcedValue(): void
    {
        $clone = (string) file_get_contents(dirname(__DIR__, 2).'/ext/dom/JitDomCloneNode.php');
        $this->assertStringContainsString('boxObjectResult', $clone);
        $this->assertStringContainsString('#34024', $clone);
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('DomNodeCloneNode', $jit);
        $this->assertStringContainsString('#34024', $jit);
    }

    public function testVmCloneNodeNullsafeMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34024_dom_nullsafe_boxed_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame("r\nr\nr\nnull", trim($joined));
    }

    public function testAotCloneNodeNullsafeMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34024_dom_nullsafe_boxed_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34024_dom_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame("r\nr\nr\nnull", trim($joined));
        } finally {
            @unlink($bin);
        }
    }
}
