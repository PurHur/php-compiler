<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLWriter::toMemory / toUri leftover of openMemory/openUri (#35890 / #19606).
 *
 * @see php-src ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_toMemory / zim_XMLWriter_toUri
 *
 * @group aot-lint
 */
final class XmlWriterToMemoryToUriAotTest extends TestCase
{
    private const TOURI_PATH = '/tmp/phpc_xw_touri_aot.xml';

    private const TOMEMORY_EXPECTED = "<?xml version=\"1.0\"?>\n<hi>there</hi>\n";

    public function testVmToMemory(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(dirname(__DIR__).'/repro/xmlwriter_tomemory_aot.php');
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'xmlwriter_tomemory_aot.php'));
            $out = (string) ob_get_clean();
            $this->assertSame(self::TOMEMORY_EXPECTED, $out);
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
    public function testAotToMemoryMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/xmlwriter_tomemory_aot.php';

        $vm = [];
        exec(
            'env PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $vmOut = implode("\n", $vm)."\n";
        $this->assertSame(self::TOMEMORY_EXPECTED, $vmOut);

        $bin = sys_get_temp_dir().'/phpc_xw_tomemory_'.getmypid().'.bin';
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

    /**
     * @group llvm
     * @group aot
     */
    public function testAotToUriWritesAtCompile(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/xmlwriter_touri_aot.php';
        @unlink(self::TOURI_PATH);

        $vm = [];
        exec(
            'env PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $vmOut = implode("\n", $vm)."\n";
        $this->assertSame("ok\n", $vmOut);
        @unlink(self::TOURI_PATH);

        $bin = sys_get_temp_dir().'/phpc_xw_touri_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        // Host fold writes the URI during compile (user-script AOT side effect).
        $this->assertFileExists(self::TOURI_PATH);
        $this->assertStringContainsString('<hi>there</hi>', (string) file_get_contents(self::TOURI_PATH));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
            @unlink(self::TOURI_PATH);
        }
    }

    public function testFactoriesRegisteredInUserScriptAot(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/XmlWriterInstanceMethodJit.php');
        $this->assertStringContainsString("'xmlwriter::tomemory' => true", $jit);
        $this->assertStringContainsString("'xmlwriter::touri' => true", $jit);
        $user = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlwriter/JitXmlWriterUserScript.php');
        $this->assertStringContainsString('function tryToMemory', $user);
        $this->assertStringContainsString('function tryToUri', $user);
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/XmlWriterToMemory.php');
        $this->assertFileExists(dirname(__DIR__, 2).'/lib/JIT/Call/XmlWriterToUri.php');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlwriter_to_memory.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlwriter_to_uri.c');
    }
}
