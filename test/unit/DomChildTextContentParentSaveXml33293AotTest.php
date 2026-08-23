<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: child textContent write refreshes parent saveXML (#33293 / re-#23892).
 *
 * @see php-src ext/dom/node.c dom_node_textcontent_write
 *
 * @group llvm
 * @group aot
 */
final class DomChildTextContentParentSaveXml33293AotTest extends TestCase
{
    private const EXPECTED = "a_tc='new'\nsave_a=<a>new</a>\nsave_r=<r><a>new</a><b>keep</b></r>\nsave=<?xml version=\"1.0\"?>\n<r><a>new</a><b>keep</b></r>\n";

    public function testHelperSyncsParentInnerXml(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomElementTextContent.php');
        $this->assertStringContainsString('syncParentInnerXmlAfterTextContentWrite', $src);
        $this->assertStringContainsString('emitElementTextContentSlotSync', $src);
        $this->assertStringContainsString('rootInnerXmlReplaceChildAt', $src);
        $this->assertStringContainsString('PROP_PARENT_NODE', $src);
    }

    public function testVmMatchesExpected(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/dom_textcontent_write_savexml_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_textcontent_write_savexml_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_textcontent_write_savexml_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33293_'.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertSame(self::EXPECTED, $zend);

        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zend, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
