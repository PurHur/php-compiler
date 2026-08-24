<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: dom_import_simplexml user-script lowering (#34413).
 *
 * @group llvm
 * @group aot
 */
final class DomImportSimpleXmlAot34413Test extends TestCase
{
    private const EXPECTED = "r:1\nDONE";

    public function testAotImportSimpleXmlMatchesExpected(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34413_dom_import_simplexml_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_import_sxe_34413_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
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
