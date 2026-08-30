<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLWriter::toMemory / toUri leftover of openMemory/openUri (#19606 / #35872).
 *
 * @see php-src ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toMemory / zim_XMLWriter_toUri
 *
 * @group aot-lint
 */
final class XmlWriterToMemoryAotTest extends TestCase
{
    private const URI_PATH = '/tmp/phpc_xw_touri_aot.xml';

    public function testVmToMemory(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsXmlWriterFactories()) {
                self::markTestSkipped('XMLWriter factories need PHP_COMPILER_PROFILE=8.4');
            }
            $runtime = new Runtime();
            $code = file_get_contents(dirname(__DIR__).'/repro/xmlwriter_tomemory_aot.php');
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'xmlwriter_tomemory_aot.php'));
            $out = (string) ob_get_clean();
            $this->assertStringContainsString('<child>hi</child>', $out);
            $this->assertStringContainsString('<root>', $out);
        } finally {
            putenv('PHP_COMPILER_PROFILE');
        }
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotToMemoryMatchVm(): void
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
            $src = $root.'/test/repro/xmlwriter_tomemory_aot.php';
            $env = 'PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 ';

            $vm = [];
            exec(
                $env.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
                .escapeshellarg($src).' 2>&1',
                $vm,
                $vmRc
            );
            $this->assertSame(0, $vmRc, implode("\n", $vm));
            $vmOut = implode("\n", $vm)."\n";
            $this->assertStringContainsString('<child>hi</child>', $vmOut);

            $bin = sys_get_temp_dir().'/phpc_xw_tomemory_'.getmypid().'.bin';
            $compile = 'env '.$env.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
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
        } finally {
            putenv('PHP_COMPILER_PROFILE');
        }
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotToUriMatchVmAndWritesAtCompile(): void
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
            $src = $root.'/test/repro/xmlwriter_touri_aot.php';
            @unlink(self::URI_PATH);
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
            $this->assertFileExists(self::URI_PATH);
            $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::URI_PATH));
            @unlink(self::URI_PATH);

            $bin = sys_get_temp_dir().'/phpc_xw_touri_'.getmypid().'.bin';
            $compile = 'env '.$env.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $this->assertFileExists(self::URI_PATH);
            $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::URI_PATH));
            try {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, implode("\n", $runOut));
                $this->assertSame("ok\n", implode("\n", $runOut)."\n");
            } finally {
                @unlink($bin);
                @unlink(self::URI_PATH);
            }
        } finally {
            putenv('PHP_COMPILER_PROFILE');
        }
    }

    public function testFactoriesRegisteredInUserScriptAot(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/XmlWriterInstanceMethodJit.php');
        $this->assertStringContainsString("'xmlwriter::tomemory' => true", $jit);
        $this->assertStringContainsString("'xmlwriter::touri' => true", $jit);
        $method = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlwriter/JitXmlWriterMethod.php');
        $this->assertStringContainsString('tryToMemory', $method);
        $this->assertStringContainsString('tryToUri', $method);
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/XmlWriterToMemory.php');
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/XmlWriterToUri.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlwriter_to_memory.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlwriter_to_uri.c');
    }
}
