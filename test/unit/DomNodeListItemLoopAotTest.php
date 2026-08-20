<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMNodeList::item($i) with for/while index (#32831).
 *
 * php-src: ext/dom/nodelist.c php_dom_nodelist_item
 *
 * @group llvm
 * @group aot
 */
final class DomNodeListItemLoopAotTest extends TestCase
{
    private const EXPECTED =
        "held0=a\nheld1=b\nheld2=c\nwhile0=a\nwhile1=b\nwhile2=c\nlit0=a\nlit1=b\nlit2=c\n";

    public function testVmDomNodeListItemLoop(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32831_dom_nodelist_item_loop.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32831_dom_nodelist_item_loop.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDomNodeListItemLoop(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32831_dom_nodelist_item_loop.php';
        $bin = sys_get_temp_dir().'/phpc_dom_nli_loop_'.getmypid().'.bin';
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
