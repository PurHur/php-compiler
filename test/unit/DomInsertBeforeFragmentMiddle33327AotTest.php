<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: insertBefore(DocumentFragment) before middle child — saveXML (#33327).
 *
 * php-src: ext/dom/node.c dom_node_insert_before
 *
 * @group llvm
 * @group aot
 */
final class DomInsertBeforeFragmentMiddle33327AotTest extends TestCase
{
    private const EXPECTED = "len=4\n"
        ."i0=a\n"
        ."i1=x\n"
        ."i2=y\n"
        ."i3=b\n"
        ."xml=<r><a/><x/><y/><b/></r>\n";

    public function testVmInsertBeforeFragmentMiddle(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/dom_insertbefore_fragment_middle_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_insertbefore_fragment_middle_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotInsertBeforeFragmentMiddle(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_insertbefore_fragment_middle_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_ib_mid_'.getmypid().'.bin';
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
