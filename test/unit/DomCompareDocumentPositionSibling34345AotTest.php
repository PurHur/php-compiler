<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: compareDocumentPosition sibling walk uses nextSibling (#34345, re-#25878).
 *
 * @group llvm
 * @group aot
 */
final class DomCompareDocumentPositionSibling34345AotTest extends TestCase
{
    private const EXPECTED = "4|2\n20\n10\n4";

    public function testAotSiblingAndAncestorMatchVm(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34345_dom_cdp_sibling_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_cdp_34345_'.getmypid();
        $env = 'PHP_COMPILER_PROFILE=8.4 ';
        $compile = $env.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame(self::EXPECTED, rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
