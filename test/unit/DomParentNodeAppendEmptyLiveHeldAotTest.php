<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held childNodes after append/prepend onto empty element (#32834).
 *
 * php-src: ext/dom/node.c dom_node_append_child / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomParentNodeAppendEmptyLiveHeldAotTest extends TestCase
{
    private const EXPECTED =
        "append_held_len=1\nappend_held0=z\nappend_refetch_len=1\n"
        ."prepend_held_len=1\nprepend_held0=y\nprepend_refetch_len=1\n";

    public function testVmParentNodeAppendEmptyLiveHeld(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32834_dom_parentnode_append_empty_live_held.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32834_dom_parentnode_append_empty_live_held.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotParentNodeAppendEmptyLiveHeld(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32834_dom_parentnode_append_empty_live_held.php';
        $bin = sys_get_temp_dir().'/phpc_dom_append_empty_held_'.getmypid().'.bin';
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
