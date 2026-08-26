<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementsByTagName(NS) after loadXML namespaced children (#34936).
 *
 * php-src matches local name — `<x:a>` counts for getElementsByTagName('a').
 *
 * @see php-src ext/dom/nodelist.c php_dom_nodelist_item
 *
 * @group llvm
 * @group aot
 */
final class GetElementsByTagNameNs34936AotTest extends TestCase
{
    private const EXPECTED = "len=1\narray (\n  0 => 'x',\n  1 => 'a',\n  2 => 'urn:x',\n  3 => 'hi',\n)\narray (\n  0 => 'x',\n  1 => 'a',\n  2 => 'urn:x',\n  3 => 'hi',\n)\n";

    public function testVmGetElementsByTagNameNsAfterLoadXml(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34936_getelementsbytagname_ns_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34936_getelementsbytagname_ns_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotGetElementsByTagNameNsAfterLoadXml(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34936_getelementsbytagname_ns_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34936_ge_'.getmypid().'.bin';
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
