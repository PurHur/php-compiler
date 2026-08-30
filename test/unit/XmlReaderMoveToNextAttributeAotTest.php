<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLReader::moveToNextAttribute leftover of moveToAttribute (#35952 / #35941 / #27299).
 *
 * @see php-src ext/xmlreader/php_xmlreader.c zim_XMLReader_moveToNextAttribute
 *
 * @group aot-lint
 */
final class XmlReaderMoveToNextAttributeAotTest extends TestCase
{
    private const EXPECTED = "true\n'id'\n'1'\n2\ntrue\n'x'\n'2'\nfalse\n'x'\n";

    public function testVm(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(dirname(__DIR__).'/repro/xmlreader_movetonextattribute_aot.php');
            $this->assertNotFalse($code);
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'xmlreader_movetonextattribute_aot.php'));
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
        $src = $root.'/test/repro/xmlreader_movetonextattribute_aot.php';
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

        $bin = sys_get_temp_dir().'/phpc_xr_mtna_'.getmypid().'.bin';
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
        $this->assertStringContainsString("'xmlreader::movetonextattribute' => true", $jit);
        $method = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlreader/JitXmlReaderMethod.php');
        $this->assertStringContainsString('tryMoveToNextAttribute', $method);
        $user = (string) file_get_contents(dirname(__DIR__, 2).'/ext/xmlreader/JitXmlReaderUserScript.php');
        $this->assertStringContainsString('function tryMoveToNextAttribute', $user);
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/xmlreader_move_to_next_attribute.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/xmlreader_move_to_next_attribute.c');
    }
}
