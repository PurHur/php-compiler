<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: nested SimpleXMLElement property write leftover of #35820 (#35834).
 *
 * php-src: ext/simplexml/sxe.c sxe_property_write / zim_simplexmlelement___set
 *
 * @group llvm
 * @group aot
 */
final class SimpleXmlNestedPropSetAotTest extends TestCase
{
    private const EXPECTED = "<?xml version=\"1.0\"?>\n<root><a><b>new</b></a></root>\nnew";

    public function testVmNestedPropSetMatchesZendShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/simplexml_prop_set_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'simplexml_prop_set_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotNestedPropSetMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/simplexml_prop_set_aot.php';
        $bin = sys_get_temp_dir().'/phpc_sxe_nested_pset_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
