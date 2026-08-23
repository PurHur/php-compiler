<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: saveXML(?DOMNode $node) accepts documentElement / createElement temps (#34225 regression).
 *
 * @see php-src ext/dom/php_dom.stub.php ?DOMNode $node
 *
 * @group llvm
 * @group aot
 */
final class DomSaveXmlNodeScopedAotTest extends TestCase
{
    public function testVmSaveXmlNodeScoped(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34136_dom_childnode_after_append_tail_savexml_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34136_dom_childnode_after_append_tail_savexml_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("<r><b/><c/></r>\n", $out);
    }

    public function testAotSaveXmlNodeScoped(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34136_dom_childnode_after_append_tail_savexml_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_savexml_node_'.getmypid().'.bin';
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
            $this->assertSame("<r><b/><c/></r>\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
