<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML must materialize $doc->doctype (#34887).
 *
 * @group llvm
 * @group aot
 */
final class Issue34887DomLoadXmlDoctypePropAotTest extends TestCase
{
    public function testLoadXmlDoctypePropMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34887_dom_loadxml_doctype_prop_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_34887_'.getmypid().'.bin';
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
        $this->assertSame(0, $runRc, 'aot run failed: '.implode("\n", $out));
        $this->assertSame(implode("\n", $expected), implode("\n", $out));
    }

    public function testDoctypeLlvmExposesParseAndIsAttached(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/dom/DomUserScriptDoctypeLlvm.php');
        $this->assertStringContainsString('parseFromXml', $src);
        $this->assertStringContainsString('isAttached', $src);
        $this->assertStringContainsString('#34887', $src);
        $load = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomLoadXMLUserScript.php');
        $this->assertStringContainsString('storeDoctypeProperty', $load);
    }
}
