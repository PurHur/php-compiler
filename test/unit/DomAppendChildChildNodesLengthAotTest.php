<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: createElement appendChild then childNodes->length must not SIGSEGV (#36018 regression).
 *
 * php-src: ext/dom/node.c dom_node_child_nodes_read / nodelist.c
 *
 * @group llvm
 * @group aot
 */
final class DomAppendChildChildNodesLengthAotTest extends TestCase
{
    /**
     * @dataProvider reproProvider
     */
    public function testAotChildNodesLengthAfterAppendMatchesZend(string $repro): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$repro;
        $bin = sys_get_temp_dir().'/phpc_dom_cn_len_'.md5($repro).'_'.getmypid().'.bin';
        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zRc);
        $this->assertSame(0, $zRc, implode("\n", $zend));
        $expected = implode("\n", $zend)."\n";
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expected, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reproProvider(): iterable
    {
        yield 'element append' => ['aot_dom_element_childnodes_length.php'];
        yield 'fragment append' => ['aot_dom_fragment_childnodes_length.php'];
    }
}
