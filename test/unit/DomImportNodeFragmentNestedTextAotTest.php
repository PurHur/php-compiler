<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: importNode(DocumentFragment) nested text / cloneNode — #35997 leftover of #35881.
 *
 * @group llvm
 * @group aot
 */
final class DomImportNodeFragmentNestedTextAotTest extends TestCase
{
    /**
     * @dataProvider reproProvider
     */
    public function testAotFragmentImportMatchesZend(string $repro): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$repro;
        $bin = sys_get_temp_dir().'/phpc_dom_imp_frag35997_'.md5($repro).'_'.getmypid().'.bin';
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
        yield 'nested createElement text' => ['aot_dom_importnode_fragment_nested_text.php'];
        yield 'cloneNode with text' => ['aot_dom_importnode_fragment_clonenode.php'];
        yield 'empty cloneNode' => ['aot_dom_importnode_fragment_empty_clone.php'];
    }
}
