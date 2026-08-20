<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMElement::$childNodes->length after loadXML / createElement.
 *
 * php-src: ext/dom/node.c php_dom_get_nodelist / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodesLengthAotTest extends TestCase
{
    private const EXPECTED = "empty:0\nloaded:2\ncreate:2|b\n";

    public function testVmChildNodesLength(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_childnodes_length_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_childnodes_length_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotChildNodesLength(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_childnodes_length_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_cn_len_'.getmypid().'.bin';
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
