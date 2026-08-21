<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMNode::insertBefore / replaceChild cross-parent reparent (#33450).
 *
 * php-src: ext/dom/node.c dom_node_insert_before / dom_node_replace_child
 *
 * @group llvm
 * @group aot
 */
final class DomCrossParentInsertBeforeReplaceChild33450AotTest extends TestCase
{
    private const EXPECTED =
        "ib_xml=<r><p1/><p2><n/><z/></p2></r>\n"
        ."ib_p1_len=0 ib_p2_len=2\n"
        ."ib_n_parent=p2\n"
        ."ib_item0=n\n"
        ."rc_same_xml=<r><a><x/></a><b/></r>\n"
        ."rc_cross_xml=<r><p1/><p2><moved/></p2></r>\n"
        ."rc_p1_len=0 rc_p2_len=1\n"
        ."rc_moved_parent=p2\n";

    public function testVmCrossParentInsertBeforeReplaceChild(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/dom_cross_parent_insertbefore_replacechild_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run(
            $runtime->parseAndCompile($code, 'dom_cross_parent_insertbefore_replacechild_aot.php')
        );
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotCrossParentInsertBeforeReplaceChild(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_cross_parent_insertbefore_replacechild_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_ib_rc_xpar_'.getmypid().'.bin';
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
