<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML seeds firstElementChild / element siblings (#34352, re-#19431).
 *
 * @group llvm
 * @group aot
 */
final class DomLoadXmlElementNav34352AotTest extends TestCase
{
    private const EXPECTED = "nextEl=b\nfirstEl=a\ncount=3\ntag=a\nprevEl=a";

    public function testAotLoadXmlElementNavMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_dom_loadxml_element_nav.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_nav_34352_'.getmypid();
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
