<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createElementNS + appendChild + document-wide saveXML (#34918).
 *
 * @see php-src ext/dom/document.c PHP_METHOD(DOMDocument, createElementNS)
 * @see php-src ext/dom/document.c xmlDocDumpMemory
 *
 * @group llvm
 * @group aot
 */
final class DomCreateElementNSDocSave34918AotTest extends TestCase
{
    private const EXPECTED = "<?xml version=\"1.0\"?>\n<x:item xmlns:x=\"urn:x\"/>\n";

    public function testVmCreateElementNSDocumentSaveXml(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34918_dom_createelementns_doc_save.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34918_dom_createelementns_doc_save.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotCreateElementNSDocumentSaveXml(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34918_dom_createelementns_doc_save.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34918_cens_'.getmypid().'.bin';
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
}
