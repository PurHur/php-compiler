<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: replaceChild(DocumentFragment) expands children (#33322).
 *
 * @see php-src ext/dom/node.c dom_node_replace_child
 *
 * @group llvm
 * @group aot
 */
final class DomReplaceChildFragment33322AotTest extends TestCase
{
    private const EXPECTED = "old=a\nlen=3\ni0=x\ni1=y\ni2=b\nxml=<r><x/><y/><b/></r>\n";

    public function testVmReplaceChildFragment(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/dom_replacechild_fragment_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_replacechild_fragment_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotReplaceChildFragment(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_replacechild_fragment_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33322_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='
            .escapeshellarg(sys_get_temp_dir().'/phpc-hr-33322-'.getmypid())
            .' '.escapeshellarg(PHP_BINARY).' '
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
