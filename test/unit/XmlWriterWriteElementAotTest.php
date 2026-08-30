<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: XMLWriter::writeElement leftover of writeElementNS (#35865 / #19371).
 *
 * php-src: ext/xmlwriter/php_xmlwriter.c zim_XMLWriter_writeElement
 *
 * @group llvm
 * @group aot
 */
final class XmlWriterWriteElementAotTest extends TestCase
{
    public function testAotWriteElementMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/xmlwriter_write_element_aot.php';
        $this->assertFileExists($src);

        $vm = [];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1',
            $vm,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vm));
        $vmOut = implode("\n", $vm)."\n";
        $this->assertStringContainsString('<child>hi</child>', $vmOut);
        $this->assertStringContainsString('<empty/>', $vmOut);
        $this->assertStringContainsString('<![CDATA[x<y]]>', $vmOut);
        $this->assertStringContainsString('<!--c-->', $vmOut);

        $bin = sys_get_temp_dir().'/phpc_xw_we_'.getmypid().'.bin';
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
            $this->assertSame($vmOut, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
