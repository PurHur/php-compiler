<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLReader::readInnerXml / readOuterXml leftover of fromString (#35908 / #27299).
 *
 * @see php-src ext/xmlreader/php_xmlreader.c zim_XMLReader_readInnerXml / readOuterXml
 *
 * @group aot-lint
 */
final class XmlReaderReadInnerOuterAotTest extends TestCase
{
    private const EXPECTED = "inner=<b>x</b>\nouter=<a><b>x</b></a>\n";

    public function testVm(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(dirname(__DIR__).'/repro/xmlreader_read_inner_outer_aot.php');
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'xmlreader_read_inner_outer_aot.php'));
            $this->assertSame(self::EXPECTED, (string) ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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
        $src = $root.'/test/repro/xmlreader_read_inner_outer_aot.php';
        $vm = [];
        exec(
            'env PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $vmOut = implode("\n", $vm)."\n";
        $this->assertSame(self::EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_xr_rio_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
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

    public function testRegisteredInUserScriptAot(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/XmlReaderInstanceMethodJit.php');
        $this->assertStringContainsString("'xmlreader::readinnerxml' => true", $jit);
        $this->assertStringContainsString("'xmlreader::readouterxml' => true", $jit);
        $method = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlreader/JitXmlReaderMethod.php');
        $this->assertStringContainsString('tryReadInnerXml', $method);
        $this->assertStringContainsString('tryReadOuterXml', $method);
        $user = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlreader/JitXmlReaderUserScript.php');
        $this->assertStringContainsString('function tryReadInnerXml', $user);
        $this->assertStringContainsString('function tryReadOuterXml', $user);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlreader_read_inner_xml.c');
    }
}
