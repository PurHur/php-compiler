<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: textContent detach marks last held sibling freed — Zend Error on parentNode (#20646 / #23892).
 *
 * @see php-src ext/dom/php_dom.c dom_objects_not_found / php_libxml_node_free_list
 *
 * @group llvm
 * @group aot
 */
final class DomTextContentDetachStaleNodeAotTest extends TestCase
{
    private const REPRO = __DIR__.'/../repro/dom_textcontent_detach_stale_node.php';

    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(self::REPRO);
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dom_textcontent_detach_stale_node.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('a_parent_null=true', $out);
        $this->assertStringContainsString('b_parent_err=', $out);
        $this->assertStringContainsString('Node no longer exists', $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_dom_tc_detach_'.getmypid().'.bin';

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::REPRO).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";

        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg(self::REPRO).' 2>&1';
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
