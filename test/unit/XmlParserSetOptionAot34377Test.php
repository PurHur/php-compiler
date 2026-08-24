<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: xml_parser_set_option / get_option user-script lowering (#34377).
 *
 * @group llvm
 * @group aot
 */
final class XmlParserSetOptionAot34377Test extends TestCase
{
    private const EXPECTED = "set=true\nfold=0\nsetEnc=true\nenc='UTF-8'\nparse=ok\nDONE";

    public function testAotSetGetOptionMatchesExpected(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34377_xml_parser_set_option_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_xml_setopt_34377_'.getmypid();
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
