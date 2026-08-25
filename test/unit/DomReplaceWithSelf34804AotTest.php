<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: ChildNode::replaceWith(self) / args including receiver (#34804).
 *
 * php-src: ext/dom/parentnode.c dom_child_replace_with / dom_zvals_to_fragment
 *
 * @group llvm
 * @group aot
 */
final class DomReplaceWithSelf34804AotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
self=a,b,c parent=r
self,x=a,b,c,#text parent=r
x,self=a,b,#text,c parent=r
self,a=b,c,a parent=r
a,self=b,a,c parent=r
empty=a,b parent=DETACHED
insertBefore_self=ERR:Error:Cannot add newnode as the previous sibling of refnode
TXT;

    public function testVmReplaceWithSelfMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_childnode_replacewith_self_aot.php';
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

    public function testAotReplaceWithSelfMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_childnode_replacewith_self_aot.php';
        $bin = sys_get_temp_dir().'/phpc_rw_self_34804_'.getmypid().'.bin';
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
