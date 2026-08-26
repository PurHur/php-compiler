<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: createAttributeNS Attr value write updates qName open-tag (#34926).
 *
 * @group llvm
 * @group aot
 */
final class Issue34926CreateAttributeNsValueSaveXmlAotTest extends TestCase
{
    public function testCreateAttributeNsValueWriteSaveXmlMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/dom_createAttributeNS_value_savexml_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_34926_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = [PHP_BINARY, $root.'/bin/compile.php', '-o', $bin, $src];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 500));

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $out));
        $this->assertSame(implode("\n", $expected), implode("\n", $out));
        $joined = implode("\n", $out);
        $this->assertStringContainsString('x:id="1"', $joined);
        $this->assertStringNotContainsString(' id="1"', $joined);
    }

    public function testAttrValueWriteUsesOpenTagQName(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomElementTextContent.php');
        $this->assertStringContainsString('openTagAttrUpdates', $src);
        $this->assertStringContainsString('#34926', $src);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/dom_attrns_value_savexml.c');
    }
}
