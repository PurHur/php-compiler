<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\dom\JitDomCloneNode;
use PHPUnit\Framework\TestCase;

/**
 * AOT: cloneNode(firstChild) xmlDocCopyNode + saveXML xmlNodeDump (#32355).
 *
 * @see php-src ext/dom/node.c php_dom_clone_node
 *
 * @group llvm
 * @group aot
 */
final class DomCloneNode32355AotTest extends TestCase
{
    private const EXPECTED = "child|<child id=\"1\"><inner/></child>|<child id=\"1\"/>END\n";

    public function testParseElementMarkupDeepAndShallow(): void
    {
        $parsed = JitDomCloneNode::parseElementMarkup('<child id="1"><inner/></child>');
        $this->assertNotNull($parsed);
        $this->assertSame('child', $parsed['tag']);
        $this->assertSame(' id="1"', $parsed['attrSuffix']);
        $this->assertSame('<inner/>', $parsed['inner']);
    }

    public function testVmCloneNodeSaveXml(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_clonenode_savexml_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_clonenode_savexml_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotCloneNodeSaveXml(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_clonenode_savexml_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32355_clone_'.getmypid().'.bin';
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

    public function testSaveXmlUserScriptDumpsCloneFromSlots(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomSaveXMLUserScript.php');
        $this->assertStringContainsString('JitDomCloneNode::hasMaterializedClone', $src);
        $this->assertStringContainsString('#32355', $src);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomCloneNode.php');
        $this->assertStringContainsString('xmlDocCopyNode', $helper);
        $this->assertStringNotContainsString('runtime/', $helper);
    }
}
