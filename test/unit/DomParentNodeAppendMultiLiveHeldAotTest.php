<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held childNodes after multi-arg ParentNode::append/prepend (#32838).
 *
 * php-src: ext/dom/parentnode.c / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomParentNodeAppendMultiLiveHeldAotTest extends TestCase
{
    private const EXPECTED =
        "append_held_len=3\n"
        ."append_held0=a\n"
        ."append_held1=b\n"
        ."append_held2=c\n"
        ."append_refetch_len=3\n"
        ."prepend_held_len=3\n"
        ."prepend_held0=b\n"
        ."prepend_held1=c\n"
        ."prepend_held2=a\n"
        ."prepend_refetch_len=3\n";

    public function testVmParentNodeAppendMultiLiveHeld(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32838_dom_parentnode_append_multi_live_held.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32838_dom_parentnode_append_multi_live_held.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotParentNodeAppendMultiLiveHeld(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32838_dom_parentnode_append_multi_live_held.php';
        $bin = sys_get_temp_dir().'/phpc_dom_append_multi_held_'.getmypid().'.bin';
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
