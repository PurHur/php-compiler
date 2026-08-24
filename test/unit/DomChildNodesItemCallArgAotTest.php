<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: insertBefore/replaceChild(createElement, $el->childNodes->item(N)) (#34436).
 *
 * php-src: ext/dom/node.c php_dom_insert_before / dom_node_replace_child
 *
 * @group llvm
 * @group aot
 */
final class DomChildNodesItemCallArgAotTest extends TestCase
{
    public function testVmInsertBeforeMatchesZend(): void
    {
        $this->assertSame(
            "len=3\nitem1=b\nxml=<r><a/><b/><c/></r>\n",
            $this->runVm('issue_dom_childnodes_item_call_arg_aot.php')
        );
    }

    public function testVmReplaceChildMatchesZend(): void
    {
        $this->assertSame(
            "xml=<r><a/><b/><d/></r>\n",
            $this->runVm('issue_dom_childnodes_item_replacechild_aot.php')
        );
    }

    public function testAotInsertBeforeMatchesZend(): void
    {
        $this->assertSame(
            "len=3\nitem1=b\nxml=<r><a/><b/><c/></r>\n",
            $this->runAot('issue_dom_childnodes_item_call_arg_aot.php')
        );
    }

    public function testAotReplaceChildMatchesZend(): void
    {
        $this->assertSame(
            "xml=<r><a/><b/><d/></r>\n",
            $this->runAot('issue_dom_childnodes_item_replacechild_aot.php')
        );
    }

    public function testOpcodesKeepDistinctCreateElementAndItemArgs(): void
    {
        foreach ([
            'issue_dom_childnodes_item_call_arg_aot.php' => 'insertBefore',
            'issue_dom_childnodes_item_replacechild_aot.php' => 'replaceChild',
        ] as $file => $method) {
            $root = dirname(__DIR__, 2);
            $src = $root.'/test/repro/'.$file;
            $cmd = escapeshellarg(PHP_BINARY).' -d opcache.enable=0 '
                .escapeshellarg($root.'/bin/print.php').' '
                .escapeshellarg($src).' 2>/dev/null';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, $file.': '.implode("\n", $out));
            $text = implode("\n", $out);
            $this->assertStringContainsString("LITERAL('createElement')", $text, $file);
            $this->assertStringContainsString("LITERAL('{$method}')", $text, $file);
            $this->assertDoesNotMatchRegularExpression(
                '/TYPE_METHODCALL_INIT\(\$\d+, LITERAL\(\''.preg_quote($method, '/').'\'\), null\)\s+'
                .'TYPE_ARG_SEND\(\$(\d+), null, null\)\s+'
                .'TYPE_ARG_SEND\(\$\1, null, null\)/',
                $text,
                $file.' must not send the same slot for createElement + item'
            );
        }
    }

    private function runVm(string $reproFile): string
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/'.$reproFile);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, $reproFile));

        return (string) ob_get_clean();
    }

    private function runAot(string $reproFile): string
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproFile;
        $bin = sys_get_temp_dir().'/phpc_dom_cn_item_'.getmypid().'_'.md5($reproFile).'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .'-d opcache.enable=0 '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut)."\n";
        } finally {
            @unlink($bin);
        }
    }
}
