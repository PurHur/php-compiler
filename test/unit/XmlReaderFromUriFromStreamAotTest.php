<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLReader::fromUri / fromStream leftover of fromString (#35900 / #27299).
 *
 * @see php-src ext/xmlreader/php_xmlreader.c zim_xmlreader_fromUri / zim_xmlreader_fromStream
 *
 * @group aot-lint
 */
final class XmlReaderFromUriFromStreamAotTest extends TestCase
{
    private const URI_PATH = '/tmp/phpc_xr_fromuri_aot.xml';

    private const STREAM_PATH = '/tmp/phpc_xr_fromstream_aot.xml';

    private const XML = '<r><a/></r>';

    private const EXPECTED = "ra\n";

    protected function setUp(): void
    {
        file_put_contents(self::URI_PATH, self::XML);
        file_put_contents(self::STREAM_PATH, self::XML);
    }

    protected function tearDown(): void
    {
        @unlink(self::URI_PATH);
        @unlink(self::STREAM_PATH);
        putenv('PHP_COMPILER_PROFILE');
    }

    public function testVmFromUri(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/xmlreader_fromuri_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'xmlreader_fromuri_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testVmFromStream(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/xmlreader_fromstream_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'xmlreader_fromstream_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotFromUriMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesVm(
            dirname(__DIR__).'/repro/xmlreader_fromuri_aot.php',
            'fromuri'
        );
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotFromStreamMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesVm(
            dirname(__DIR__).'/repro/xmlreader_fromstream_aot.php',
            'fromstream'
        );
    }

    public function testFactoriesRegisteredInUserScriptAot(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/XmlReaderInstanceMethodJit.php');
        $this->assertStringContainsString("'xmlreader::fromuri' => true", $jit);
        $this->assertStringContainsString("'xmlreader::fromstream' => true", $jit);
        $method = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlreader/JitXmlReaderMethod.php');
        $this->assertStringContainsString('tryFromUri', $method);
        $this->assertStringContainsString('tryFromStream', $method);
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/XmlReaderFromUri.php');
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/XmlReaderFromStream.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlreader_from_uri.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlreader_from_stream.c');
    }

    private function assertAotMatchesVm(string $src, string $slug): void
    {
        $root = dirname(__DIR__, 2);
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

        $bin = sys_get_temp_dir().'/phpc_xr_'.$slug.'_'.getmypid().'.bin';
        $compileOut = [];
        $compile = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
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
