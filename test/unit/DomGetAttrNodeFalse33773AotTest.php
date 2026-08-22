<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getAttributeNode miss/null → bool(false) (#33773).
 *
 * php-src: ext/dom/element.c — classic DOMElement RETURN_FALSE on miss.
 *
 * @group llvm
 * @group aot
 */
final class DomGetAttrNodeFalse33773AotTest extends TestCase
{
    private const EXPECTED =
        "bool(false)\n".
        "bool(false)\n".
        "bool(false)\n".
        "present=DOMAttr value=v\n";

    public function testAotGetAttributeNodeMissIsFalse(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33773_dom_getattrnode_false_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dom_getattrnode_false_33773_'.getmypid().'.bin';
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
