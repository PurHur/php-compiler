<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML then $doc->doctype must match Zend (not SIGSEGV) (#34887 leftover of #34877).
 *
 * @group llvm
 * @group aot
 */
final class Issue34887DomLoadXmlDoctypePropertyAotTest extends TestCase
{
    public function testLoadXmlDoctypePropertyMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34887_dom_loadxml_doctype_property_aot.php';
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
        $this->assertSame(0, $runRc, implode("\n", $out));
        $this->assertSame(implode("\n", $expected), implode("\n", $out));
    }

    public function testHelperStoresDoctypeAndNullPath(): void
    {
        $fetch = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomDocumentDoctype.php');
        $stamp = (string) file_get_contents(__DIR__.'/../../ext/dom/DomUserScriptDoctypeLlvm.php');
        $this->assertStringContainsString('materializeFromLoadXmlStamp', $fetch);
        $this->assertStringContainsString('#34887', $fetch);
        $this->assertStringContainsString('isAttached()', $fetch);
        $this->assertStringContainsString('function isAttached', $stamp);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/dom_doctype.c');
    }
}
