<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held childNodes after multi-arg ChildNode::replaceWith (#32887).
 *
 * php-src: ext/dom/php_dom.c dom_child_replace_with / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeReplaceWithMultiLiveHeldAotTest extends TestCase
{
    private const EXPECTED =
        "held_len=3\n"
        ."held0=b\n"
        ."held1=c\n"
        ."held2=z\n"
        ."refetch_len=3\n"
        ."save=<r><b/><c/><z/></r>\n";

    public function testVmChildNodeReplaceWithMultiLiveHeld(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32887_dom_childnode_replacewith_multi_live_held.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32887_dom_childnode_replacewith_multi_live_held.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotChildNodeReplaceWithMultiLiveHeld(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32887_dom_childnode_replacewith_multi_live_held.php';
        $bin = sys_get_temp_dir().'/phpc_dom_rw_multi_held_'.getmypid().'.bin';
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
