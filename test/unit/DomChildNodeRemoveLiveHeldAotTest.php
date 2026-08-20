<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held childNodes live pins after ChildNode::remove (#32823).
 *
 * php-src: ext/dom/php_dom.c dom_child_node_remove / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeRemoveLiveHeldAotTest extends TestCase
{
    private const EXPECTED =
        "held_len=2\nheld0=a\nheld1=c\nrefetch_len=2\nrefetch1=c\n";

    public function testVmChildNodeRemoveLiveHeld(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32823_dom_childnode_remove_live_held.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32823_dom_childnode_remove_live_held.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotChildNodeRemoveLiveHeld(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32823_dom_childnode_remove_live_held.php';
        $bin = sys_get_temp_dir().'/phpc_dom_remove_held_'.getmypid().'.bin';
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
