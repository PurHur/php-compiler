<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Document ChildNode::before/after + insertBefore comment — saveXML + childNodes (#34160).
 *
 * @see php-src ext/dom/php_dom.c dom_child_node_before / dom_add_prev_sibling
 * @see php-src ext/dom/document.c saveXML → xmlDocDumpMemory
 *
 * @group llvm
 * @group aot
 */
final class Issue34160DomDocumentChildNodeBeforeCommentAotTest extends TestCase
{
    private const EXPECTED = <<<'TXT'
<?xml version="1.0"?>
<!--x-->
<r/>
len=2
item0=#comment
item1=r
<?xml version="1.0"?>
<r/>
<!--y-->
len2=2
<?xml version="1.0"?>
<!--z-->
<r/>
len3=2

TXT;

    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34160_dom_document_childnode_before_comment_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34160_dom_document_childnode_before_comment_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34160_dom_document_childnode_before_comment_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34160_'.getmypid().'.bin';
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
