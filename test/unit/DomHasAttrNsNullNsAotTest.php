<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: namespaced attr must not satisfy null-NS / bare-name lookup (xmlHasNsProp).
 *
 * @group llvm
 * @group aot
 */
final class DomHasAttrNsNullNsAotTest extends TestCase
{
    private const EXPECTED =
        "hasAttr a=0\n".
        "hasAttr p:a=1\n".
        "getAttr a=''\n".
        "getAttr p:a='1'\n".
        "hasNS urn a=1\n".
        "hasNS null a=0\n".
        "getNS urn a='1'\n".
        "getNS null a=''\n".
        "mixed hasNS null a=1\n".
        "mixed getNS null a='2'\n";

    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34330_dom_hasattrns_null_ns_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34330_dom_hasattrns_null_ns_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34330_dom_hasattrns_null_ns_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_hasattrns_null_'.getmypid().'.bin';
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
