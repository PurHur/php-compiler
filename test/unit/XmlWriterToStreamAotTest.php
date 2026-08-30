<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLWriter::toStream leftover of toMemory/toUri (#35895 / #19606).
 *
 * @see php-src ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toStream
 *
 * @group aot-lint
 */
final class XmlWriterToStreamAotTest extends TestCase
{
    private const STREAM_PATH = '/tmp/phpc_xw_tostream_aot.xml';

    public function testVmToStream(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsXmlWriterFactories()) {
                self::markTestSkipped('XMLWriter factories need PHP_COMPILER_PROFILE=8.4');
            }
            @unlink(self::STREAM_PATH);
            $runtime = new Runtime();
            $code = file_get_contents(dirname(__DIR__).'/repro/xmlwriter_tostream_aot.php');
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'xmlwriter_tostream_aot.php'));
            $out = (string) ob_get_clean();
            $this->assertSame("ok\n", $out);
            $this->assertFileExists(self::STREAM_PATH);
            $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::STREAM_PATH));
        } finally {
            @unlink(self::STREAM_PATH);
            putenv('PHP_COMPILER_PROFILE');
        }
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotToStreamMatchVmAndWritesAtCompile(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsXmlWriterFactories()) {
                self::markTestSkipped('XMLWriter factories need PHP_COMPILER_PROFILE=8.4');
            }
            $root = dirname(__DIR__, 2);
            $src = $root.'/test/repro/xmlwriter_tostream_aot.php';
            @unlink(self::STREAM_PATH);
            $env = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 ';

            $vm = [];
            exec(
                $env.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
                .escapeshellarg($src).' 2>&1',
                $vm,
                $vmRc
            );
            $this->assertSame(0, $vmRc, implode("\n", $vm));
            $this->assertSame("ok\n", implode("\n", $vm)."\n");
            $this->assertFileExists(self::STREAM_PATH);
            $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::STREAM_PATH));
            @unlink(self::STREAM_PATH);

            $bin = sys_get_temp_dir().'/phpc_xw_tostream_'.getmypid().'.bin';
            $compile = 'env '.$env.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $this->assertFileExists(self::STREAM_PATH);
            $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::STREAM_PATH));
            try {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, implode("\n", $runOut));
                $this->assertSame("ok\n", implode("\n", $runOut)."\n");
            } finally {
                @unlink($bin);
                @unlink(self::STREAM_PATH);
            }
        } finally {
            putenv('PHP_COMPILER_PROFILE');
        }
    }

    public function testFactoryRegisteredInUserScriptAot(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/XmlWriterInstanceMethodJit.php');
        $this->assertStringContainsString("'xmlwriter::tostream' => true", $jit);
        $method = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlwriter/JitXmlWriterMethod.php');
        $this->assertStringContainsString('tryToStream', $method);
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/XmlWriterToStream.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlwriter_to_stream.c');
        $fopen = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/fopen.php');
        $this->assertStringContainsString('jitFopenLiteralPath', $fopen);
    }
}
