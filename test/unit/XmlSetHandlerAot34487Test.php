<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: xml_set_element_handler / xml_set_character_data_handler Closures (#34487).
 *
 * @group llvm
 * @group aot
 */
final class XmlSetHandlerAot34487Test extends TestCase
{
    public function testAotElementHandlerMatchesZend(): void
    {
        $this->assertAotMatches(
            'issue_34487_xml_set_element_handler_aot.php',
            "S:R\nS:C\nE:C\nE:R"
        );
    }

    public function testAotCharacterDataHandlerMatchesZend(): void
    {
        $this->assertAotMatches(
            'issue_34487_xml_set_character_data_handler_aot.php',
            "D:hi"
        );
    }

    public function testVmElementHandlerMatchesZend(): void
    {
        $this->assertVmMatches(
            'issue_34487_xml_set_element_handler_aot.php',
            "S:R\nS:C\nE:C\nE:R"
        );
    }

    public function testVmCharacterDataHandlerMatchesZend(): void
    {
        $this->assertVmMatches(
            'issue_34487_xml_set_character_data_handler_aot.php',
            "D:hi"
        );
    }

    private function assertAotMatches(string $reproName, string $expected): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproName;
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_xml_set_34487_'.md5($reproName).'_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame($expected, rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }

    private function assertVmMatches(string $reproName, string $expected): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproName;
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, "VM run rc=$rc out=".implode("\n", $out));
        $this->assertSame($expected, rtrim(implode("\n", $out)));
    }
}
