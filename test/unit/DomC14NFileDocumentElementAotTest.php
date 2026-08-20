<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: documentElement->C14NFile() after loadXML (#32964).
 *
 * Untyped documentElement temps must remap to DOMNode::C14NFile (peer C14N #32961).
 *
 * @see php-src ext/dom/node.c dom_node_c14n
 *
 * @group llvm
 * @group aot
 */
final class DomC14NFileDocumentElementAotTest extends TestCase
{
    public function testVmDocumentElementC14NFile(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_dom_c14nfile_documentelement_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_dom_c14nfile_documentelement_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/^int\(13\)\n<r a="1"><\/r>\n$/', $out);
    }

    public function testAotDocumentElementC14NFile(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_dom_c14nfile_documentelement_aot.php';
        $bin = sys_get_temp_dir().'/phpc_c14nfile_32964_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertMatchesRegularExpression(
            '/^int\(13\)\n<r a="1"><\/r>$/',
            implode("\n", $runOut)
        );
    }
}
