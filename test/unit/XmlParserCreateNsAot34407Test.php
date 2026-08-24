<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: xml_parser_create_ns user-script lowering (#34407).
 *
 * @group llvm
 * @group aot
 */
final class XmlParserCreateNsAot34407Test extends TestCase
{
    private const EXPECTED = "URN:X:R,URN:X:A,URN:X:R\nURN:X R,URN:X A,URN:X R\nurn:x:r,urn:x:a,urn:x:r";

    public function testAotCreateNsIntoStructMatchesExpected(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/xml_parser_create_ns_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_xml_create_ns_34407_'.getmypid();
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

    public function testVmCreateNsIntoStructMatchesExpected(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/xml_parser_create_ns_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, "VM run rc=$rc out=".implode("\n", $out));
        $this->assertSame(self::EXPECTED, rtrim(implode("\n", $out)));
    }
}
