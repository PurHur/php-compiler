<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMDocument::adoptNode() cross-document reparent after loadXML (#19654, #29853).
 *
 * @group llvm
 * @group aot
 */
final class DomAdoptNode19654AotTest extends TestCase
{
    public function testLoadXmlTreeAdoptReparentAndAppend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_19654_domdocument_adoptnode.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_adopt_19654_'.getmypid();
        $env = 'PHP_COMPILER_PROFILE=8.4 ';
        $compile = $env.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec($env.escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $text = implode("\n", $aot);
            $this->assertStringContainsString("n\n<a/>", $text);
            $this->assertStringContainsString('<b><n>t</n></b>', $text);
            $this->assertStringContainsString('owner=d2', $text);
            $this->assertStringContainsString('same-object', $text);
        } finally {
            @unlink($bin);
        }
    }

    public function testMethodExistsGuardRepro(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_domdocument_adoptnode.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_adopt_gap_'.getmypid();
        $env = 'PHP_COMPILER_PROFILE=8.4 ';
        $compile = $env.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));

        try {
            $aot = [];
            exec($env.escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame('ok', trim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
