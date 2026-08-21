<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DocumentFragment appendChild / insertBefore expands children (#33312).
 *
 * php-src: ext/dom/node.c dom_node_append_child / dom_node_insert_before
 *
 * @group llvm
 * @group aot
 */
final class DomAppendChildFragment33312AotTest extends TestCase
{
    private const EXPECTED = "append_len=3\n"
        ."append_xml=<r><a/><b/><c/></r>\n"
        ."append_i1=b append_i2=c\n"
        ."ib_len=3\n"
        ."ib_xml=<r><b/><c/><z/></r>\n"
        ."ib_i0=b ib_i1=c ib_i2=z\n";

    public function testVmDocumentFragmentExpand(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_33312_dom_appendchild_fragment_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33312_dom_appendchild_fragment_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDocumentFragmentExpand(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33312_dom_appendchild_fragment_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_frag_'.getmypid().'.bin';
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
