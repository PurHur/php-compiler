<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: middle-child parentNode + held getElementsByTagName after replaceChild (#34590).
 *
 * php-src: ext/dom/node.c dom_node_replace_child / nodelist.c
 * Peer: #27411 parentNode stale check; #33679 removeChild live tag count.
 *
 * @group llvm
 * @group aot
 */
final class DomReplaceChildLiveTagList34590AotTest extends TestCase
{
    private const MIDDLE_PARENT = __DIR__.'/../repro/dom_middle_child_parentnode_aot.php';

    private const LIVE_REPLACE = __DIR__.'/../repro/dom_live_nodelist_replace_aot.php';

    public function testMiddleChildParentNodeVm(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(self::MIDDLE_PARENT);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_middle_child_parentnode_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("fc=r\nmid=r\ni1=r\n", $out);
    }

    public function testMiddleChildParentNodeAot(): void
    {
        $this->assertAotMatchesZend(self::MIDDLE_PARENT, 'phpc_dom_mid_pn_');
    }

    public function testLiveTagListReplaceVm(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(self::LIVE_REPLACE);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_live_nodelist_replace_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("before=3\nafter=2 item0=a\n", $out);
    }

    public function testLiveTagListReplaceAot(): void
    {
        $this->assertAotMatchesZend(self::LIVE_REPLACE, 'phpc_dom_live_rc_');
    }

    private function assertAotMatchesZend(string $src, string $binPrefix): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/'.$binPrefix.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

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
            $this->assertSame($zend, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
