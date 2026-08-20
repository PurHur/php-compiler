<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: held childNodes after multi-arg ChildNode::after/before (#32848).
 *
 * php-src: ext/dom/php_dom.c / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodeAfterMultiLiveHeldAotTest extends TestCase
{
    private const EXPECTED =
        "after_held_len=3\n"
        ."after_held0=a\n"
        ."after_held1=b\n"
        ."after_held2=c\n"
        ."after_refetch_len=3\n"
        ."before_held_len=3\n"
        ."before_held0=b\n"
        ."before_held1=c\n"
        ."before_held2=a\n"
        ."before_refetch_len=3\n";

    public function testVmChildNodeAfterMultiLiveHeld(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32848_dom_childnode_after_multi_live_held.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32848_dom_childnode_after_multi_live_held.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotChildNodeAfterMultiLiveHeld(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32848_dom_childnode_after_multi_live_held.php';
        $bin = sys_get_temp_dir().'/phpc_dom_after_multi_held_'.getmypid().'.bin';
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
