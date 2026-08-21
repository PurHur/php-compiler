<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMNode::appendChild cross-parent reparent (#33404).
 *
 * php-src: ext/dom/node.c dom_node_append_child
 *
 * @group llvm
 * @group aot
 */
final class DomAppendChildCrossParentMove33404AotTest extends TestCase
{
    private const EXPECTED =
        "xml=<r><p1/><p2><n/></p2></r>\n"
        ."p1_len=0 p2_len=1\n"
        ."n_parent=p2\n"
        ."p1_xml=<p1/> p2_xml=<p2><n/></p2>\n"
        ."item0_same=1\n";

    public function testVmAppendChildCrossParentMove(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/dom_appendchild_cross_parent_move_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_appendchild_cross_parent_move_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotAppendChildCrossParentMove(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_appendchild_cross_parent_move_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_ac_xpar_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
