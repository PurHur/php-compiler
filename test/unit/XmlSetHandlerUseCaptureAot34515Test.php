<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: xml_set_*_handler Closures with use() must not wrong-target (#34515 / re-#34487).
 *
 * @group llvm
 * @group aot
 */
final class XmlSetHandlerUseCaptureAot34515Test extends TestCase
{
    public function testAotElementHandlerWithUseMatchesZend(): void
    {
        $this->assertAotMatches(
            'issue_34515_xml_set_element_handler_use_aot.php',
            "S:R\nS:C\nE:C\nE:R"
        );
    }

    public function testAotCombinedHandlersWithRefLogMatchesZend(): void
    {
        $this->assertAotMatches(
            'issue_34515_xml_set_handlers_use_log_aot.php',
            'S:R|D:hi|E:R'
        );
    }

    public function testEchoOnlyElementHandlerStillMatchesZend(): void
    {
        $this->assertAotMatches(
            'issue_34487_xml_set_element_handler_aot.php',
            "S:R\nS:C\nE:C\nE:R"
        );
    }

    public function testVmElementHandlerWithUseMatchesZend(): void
    {
        $this->assertVmMatches(
            'issue_34515_xml_set_element_handler_use_aot.php',
            "S:R\nS:C\nE:C\nE:R"
        );
    }

    private function assertAotMatches(string $reproName, string $expected): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$reproName;
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_xml_set_34515_'.md5($reproName).'_'.getmypid();
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
