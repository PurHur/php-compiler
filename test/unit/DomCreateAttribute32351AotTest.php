<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createAttribute + saveXML xmlNodeDump ` name="value"` (#32351).
 *
 * @see php-src ext/dom/document.c PHP_METHOD(DOMDocument, createAttribute)
 *
 * @group llvm
 * @group aot
 */
final class DomCreateAttribute32351AotTest extends TestCase
{
    private const EXPECTED = "id| id=\"\"END\n";

    public function testVmCreateAttributeSaveXml(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_createattribute_savexml_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_createattribute_savexml_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotCreateAttributeSaveXml(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_createattribute_savexml_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32351_attr_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }

    public function testSaveXmlUserScriptChecksAttrClassId(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomSaveXMLUserScript.php');
        $this->assertStringContainsString('icmpObjectClassIsAttr', $src);
        $this->assertStringContainsString('#32351', $src);
        $this->assertStringContainsString('DOMAttr', $src);
    }
}
