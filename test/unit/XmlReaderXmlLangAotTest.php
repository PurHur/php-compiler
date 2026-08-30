<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: XMLReader xmlLang inherits xml:lang (#36006 leftover of #35983).
 *
 * @see php-src ext/xmlreader/php_xmlreader.c PROP_XMLLANG
 *
 * @group aot-lint
 */
final class XmlReaderXmlLangAotTest extends TestCase
{
    private const EXPECTED = "rootLang=en\nchildLang=en\ntextLang=en\nattrLang=en\n";

    public function testVm(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/xmlreader_xmllang_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'xmlreader_xmllang_aot.php'));
        $this->assertSame(self::EXPECTED, (string) ob_get_clean());
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/xmlreader_xmllang_aot.php';
        $vm = [];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $vmOut = implode("\n", $vm)."\n";
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_xr_lang_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '.escapeshellarg($bin).' '
            .escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
