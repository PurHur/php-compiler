<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLWriter::openUri leftover of openMemory (#35872 / #19551).
 *
 * @see php-src ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_openUri
 *
 * @group aot-lint
 */
final class XmlWriterOpenUriAotTest extends TestCase
{
    private const PATH = '/tmp/phpc_xw_openuri_aot.xml';

    private const EXPECTED = "open=true\n";

    public function testVmOpenUri(): void
    {
        @unlink(self::PATH);
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/xmlwriter_openuri_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'xmlwriter_openuri_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
        $this->assertFileExists(self::PATH);
        $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::PATH));
        @unlink(self::PATH);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotOpenUriMatchVmAndWritesAtCompile(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/xmlwriter_openuri_aot.php';
        @unlink(self::PATH);

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
        @unlink(self::PATH);

        $bin = sys_get_temp_dir().'/phpc_xw_openuri_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        // Host fold writes the URI during compile (user-script AOT side effect).
        $this->assertFileExists(self::PATH);
        $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::PATH));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
            @unlink(self::PATH);
        }
    }

    public function testOpenUriRegisteredInUserScriptAot(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/XmlWriterInstanceMethodJit.php');
        $this->assertStringContainsString("'xmlwriter::openuri' => true", $jit);
        $method = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlwriter/JitXmlWriterMethod.php');
        $this->assertStringContainsString('tryOpenUri', $method);
        $user = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlwriter/JitXmlWriterUserScript.php');
        $this->assertStringContainsString('function tryOpenUri', $user);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlwriter_open_uri.c');
    }
}
