<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: isSameNode(null) TypeError like Zend (#33775).
 *
 * @group llvm
 * @group aot
 */
final class DomIsSameNodeNull33775AotTest extends TestCase
{
    public function testAotIsSameNodeNullTypeError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33775_dom_issamenode_null_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_issamenode_null_33775_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame("1\n0\nTE\nTE-lit", rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
