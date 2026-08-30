<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: iterator_to_array(SimpleXMLElement) leftover of Iterator host folds (#35852 / #35844).
 *
 * php-src: ext/spl/iterator.c + ext/simplexml/sxe.c
 *
 * @group llvm
 * @group aot
 */
final class SimpleXmlIteratorToArrayAotTest extends TestCase
{
    private const EXPECTED = "a=1\nb=2\n0=1\n1=2\n";

    public function testVmIteratorToArrayMatchesZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/simplexml_iterator_to_array_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'simplexml_iterator_to_array_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotIteratorToArrayMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/simplexml_iterator_to_array_aot.php';
        $bin = sys_get_temp_dir().'/phpc_sxe_ita_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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

    public function testFoldHookAndNoNewRuntimeC(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/runtime/simplexml_iterator_to_array.c');
        $us = (string) file_get_contents($root.'/ext/simplexml/JitSimpleXmlUserScript.php');
        $this->assertStringContainsString('tryFoldIteratorToArrayHashtable', $us);
        $this->assertStringContainsString('#35852', $us);
        $ita = (string) file_get_contents($root.'/ext/standard/JitIteratorToArray.php');
        $this->assertStringContainsString('tryFoldIteratorToArrayHashtable', $ita);
    }
}
