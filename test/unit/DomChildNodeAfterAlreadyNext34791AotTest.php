<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: ChildNode::after(already-next-sibling) matches Zend (#34791).
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeAfterAlreadyNext34791AotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
after_same=<r><a/><c/></r>
after_str_node=<r><a/>x<c/></r>
after_node_str=<r><a/><c/>x</r>
insertBefore_self=Error
TXT;

    public function testVmChildNodeAfterAlreadyNextMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34791_dom_childnode_after_already_next_aot.php';
        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zend));
        $this->assertSame(self::EXPECTED."\n", implode("\n", $zend)."\n");

        $vm = [];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $this->assertSame(self::EXPECTED."\n", implode("\n", $vm)."\n");
    }

    public function testAotChildNodeAfterAlreadyNextMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34791_dom_childnode_after_already_next_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_cn_after_34791_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED."\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
