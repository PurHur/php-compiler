<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: ternary DOMNodeList::item()->nodeName must survive string CONCAT (#32908).
 *
 * Echo-as-args already matched Zend; CONCAT of the same ?: arms emptied both sides.
 * php-src: ext/dom/nodelist.c php_dom_nodelist_item / dom_node_node_name_read
 *
 * @group llvm
 * @group aot
 */
final class DomItemTernaryConcatAotTest extends TestCase
{
    private const EXPECTED = "concat=b|c\nargs=b|c\n";

    public function testVmDomItemTernaryConcat(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32908_dom_item_ternary_concat.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32908_dom_item_ternary_concat.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDomItemTernaryConcat(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32908_dom_item_ternary_concat.php';
        $bin = sys_get_temp_dir().'/phpc_dom_item_tern_concat_'.getmypid().'.bin';
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
