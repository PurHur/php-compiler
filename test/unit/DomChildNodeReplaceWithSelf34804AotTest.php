<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: ChildNode::replaceWith(self / self-in-nodes) matches Zend (#34804).
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeReplaceWithSelf34804AotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
self=<r><a/><b/><c/></r> parent=r
self_str=<r><a/><b/><c/>x</r> parent=r
str_self=<r><a/><b/>x<c/></r> parent=r
self_a=<r><b/><c/><a/></r> parent=r
a_self=<r><b/><a/><c/></r> parent=r
empty=<r><a/><b/></r>
insertBefore_self=Error
TXT;

    public function testVmChildNodeReplaceWithSelfMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34804_dom_childnode_replacewith_self_aot.php';
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

    public function testAotChildNodeReplaceWithSelfMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34804_dom_childnode_replacewith_self_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_cn_rw_self_34804_'.getmypid().'.bin';
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
