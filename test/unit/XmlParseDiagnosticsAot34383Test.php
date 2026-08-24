<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: xml_error_string / xml_get_current_* user-script lowering (#34383).
 *
 * @group llvm
 * @group aot
 */
final class XmlParseDiagnosticsAot34383Test extends TestCase
{
    private const EXPECTED = "76\nMismatched tag\n1\n11\n10\nNo error\n1\nDONE";

    public function testAotParseDiagnosticsMatchExpected(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34383_xml_parse_diagnostics_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_xml_diag_34383_'.getmypid();
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
