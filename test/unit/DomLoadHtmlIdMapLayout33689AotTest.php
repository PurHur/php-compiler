<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadHTML must not SEGV — PROP_ELEMENT_ID_MAP in DOMDocument allocate layout (#33689).
 *
 * @group llvm
 * @group aot
 */
final class DomLoadHtmlIdMapLayout33689AotTest extends TestCase
{
    public function testVmLoadHtmlGetElementById(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_33689_dom_loadhtml_idmap_layout_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33689_dom_loadhtml_idmap_layout_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("ok\ndiv\n", $out);
    }

    public function testAotLoadHtmlGetElementById(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33689_dom_loadhtml_idmap_layout_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33689_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $runOut = [];
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertSame("ok\ndiv\n", implode("\n", $runOut)."\n");
    }

    public function testAotNestedLoadHtml32996Regression(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32996_dom_loadhtml_nested_getelementbyid.php';
        $bin = sys_get_temp_dir().'/phpc_33689_n_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $runOut = [];
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertSame("ok\nhi", implode("\n", $runOut));
    }
}
