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
    private const EXPECTED = "URN:X:R,URN:X:A,URN:X:R\nurn:x r,urn:x a,urn:x r\nDONE";

    public function testAotCreateNsParseIntoStructMatchesExpected(): void
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

    public function testSourceRoutesCreateNsThroughUserScriptAot(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/ext/xml/xml_parser_create_ns.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('JitXmlParserUserScript::tryCreateNs', $src);
        $this->assertStringContainsString('requireAtMostJitArgCount', $src);
    }
}
